<?php

namespace CodeTechNL\TaskBridge;

use CodeTechNL\TaskBridge\Contracts\ConditionalJob;
use CodeTechNL\TaskBridge\Drivers\EventBridgeDriver;
use CodeTechNL\TaskBridge\Enums\RunStatus;
use CodeTechNL\TaskBridge\Enums\TriggeredBy;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Models\ScheduledJobRun;
use CodeTechNL\TaskBridge\Support\JobOutputRegistry;
use CodeTechNL\TaskBridge\Support\ScheduledJobCollection;
use CodeTechNL\TaskBridge\Support\SyncResult;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

class TaskBridge
{
    /** @var string[] */
    private array $registeredClasses = [];

    private EventBridgeDriver $eventBridge;

    public function __construct(EventBridgeDriver $eventBridge)
    {
        $this->eventBridge = $eventBridge;
    }

    /**
     * Register job classes with TaskBridge.
     *
     * @param  string[]  $jobClasses
     */
    public function register(array $jobClasses): void
    {
        foreach ($jobClasses as $class) {
            if (! in_array($class, $this->registeredClasses)) {
                $this->registeredClasses[] = $class;
            }
        }
    }

    /** @return string[] */
    public function getRegisteredClasses(): array
    {
        return $this->registeredClasses;
    }

    public function enable(string $jobClass): void
    {
        $job = $this->findOrFail($jobClass);
        $job->update(['enabled' => true]);
        $this->sync();
    }

    public function disable(string $jobClass): void
    {
        $job = $this->findOrFail($jobClass);
        $job->update(['enabled' => false]);
        $this->eventBridge->remove($job->identifier);
    }

    public function overrideCron(string $jobClass, string $cron): void
    {
        $job = $this->findOrFail($jobClass);
        $job->update(['cron_override' => $cron]);
        $this->sync();
    }

    public function resetCron(string $jobClass): void
    {
        $job = $this->findOrFail($jobClass);
        $job->update(['cron_override' => null]);
        $this->sync();
    }

    public function sync(): SyncResult
    {
        return $this->eventBridge->sync($this->enabled());
    }

    public function getEventBridge(): EventBridgeDriver
    {
        return $this->eventBridge;
    }

    /**
     * Manually run a job, recording the run when logging is enabled.
     */
    public function run(string $jobClass, bool $dryRun = false, bool $force = false): ScheduledJobRun
    {
        $record = $this->findOrFail($jobClass);
        $logging = config('taskbridge.logging.enabled', true);
        $runModel = config('taskbridge.models.scheduled_job_run', ScheduledJobRun::class);

        if (! class_exists($jobClass)) {
            throw new \InvalidArgumentException("Class not found: {$jobClass}");
        }

        $instance = new $jobClass;

        // Check enabled/shouldRun unless forced
        if (! $force) {
            if (! $record->enabled) {
                return $this->skipRun($record, $logging, $dryRun, 'Job is disabled');
            }

            if ($instance instanceof ConditionalJob && ! $instance->shouldRun()) {
                return $this->skipRun($record, $logging, $dryRun, 'shouldRun() returned false');
            }
        }

        $run = $logging ? $runModel::create([
            'scheduled_job_id' => $record->id,
            'status' => RunStatus::Running,
            'triggered_by' => $dryRun ? TriggeredBy::DryRun : TriggeredBy::Manual,
            'started_at' => now(),
            'created_at' => now(),
        ]) : null;

        $dispatchCount = 0;
        $startTime = microtime(true);

        try {
            if ($dryRun) {
                Bus::fake();
                $instance->handle();
                $dispatchCount = 0;
                Bus::assertNothingDispatched();
            } else {
                $listener = function () use (&$dispatchCount) {
                    $dispatchCount++;
                };
                Event::listen(JobQueued::class, $listener);
                $instance->handle();
                Event::forget(JobQueued::class);
            }

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $record->update(['last_run_at' => now(), 'last_status' => RunStatus::Succeeded]);

            if ($run) {
                $jobOutput = JobOutputRegistry::retrieveSuccess($jobClass);

                $run->update([
                    'status' => RunStatus::Succeeded,
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                    'jobs_dispatched' => $dispatchCount,
                    'output' => $jobOutput?->toArray(),
                ]);

                return $run->fresh();
            }
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $record->update(['last_run_at' => now(), 'last_status' => RunStatus::Failed]);

            if ($run) {
                $run->update([
                    'status' => RunStatus::Failed,
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                    'jobs_dispatched' => $dispatchCount,
                    'output' => JobOutputRegistry::retrieveError($jobClass, $e->getMessage())->toArray(),
                ]);
            }
        }

        return $run ?? new $runModel(['status' => RunStatus::Succeeded]);
    }

    public function all(): ScheduledJobCollection
    {
        $model = config('taskbridge.models.scheduled_job', ScheduledJob::class);

        return new ScheduledJobCollection($model::all()->all());
    }

    public function enabled(): ScheduledJobCollection
    {
        $model = config('taskbridge.models.scheduled_job', ScheduledJob::class);

        return new ScheduledJobCollection($model::where('enabled', true)->get()->all());
    }

    private function skipRun(ScheduledJob $record, bool $logging, bool $dryRun, string $reason): ScheduledJobRun
    {
        $runModel = config('taskbridge.models.scheduled_job_run', ScheduledJobRun::class);

        if ($logging) {
            $run = $runModel::create([
                'scheduled_job_id' => $record->id,
                'status' => RunStatus::Skipped,
                'triggered_by' => $dryRun ? TriggeredBy::DryRun : TriggeredBy::Manual,
                'started_at' => now(),
                'finished_at' => now(),
                'duration_ms' => 0,
                'skipped_reason' => $reason,
                'created_at' => now(),
            ]);

            return $run->fresh();
        }

        return new $runModel(['status' => RunStatus::Skipped]);
    }

    private function findOrFail(string $jobClass): ScheduledJob
    {
        $model = config('taskbridge.models.scheduled_job', ScheduledJob::class);
        $record = $model::where('class', $jobClass)->first();

        if (! $record) {
            throw new \InvalidArgumentException(
                "Job not found in database: {$jobClass}. Sync via the Scheduled Jobs interface first."
            );
        }

        return $record;
    }
}
