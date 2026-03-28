<?php

namespace CodeTechNL\TaskBridge\Middleware;

use CodeTechNL\TaskBridge\Contracts\ConditionalJob;
use CodeTechNL\TaskBridge\Contracts\ScheduledJob as ScheduledJobContract;
use CodeTechNL\TaskBridge\Enums\RunStatus;
use CodeTechNL\TaskBridge\Enums\TriggeredBy;
use CodeTechNL\TaskBridge\Events\JobExecutionFailed;
use CodeTechNL\TaskBridge\Events\JobExecutionSkipped;
use CodeTechNL\TaskBridge\Events\JobExecutionStarted;
use CodeTechNL\TaskBridge\Events\JobExecutionSucceeded;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Models\ScheduledJobRun;
use CodeTechNL\TaskBridge\Support\JobOutputRegistry;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;

class TaskBridgeMiddleware
{
    public function handle(mixed $job, callable $next): void
    {
        if (! ($job instanceof ScheduledJobContract)) {
            $next($job);

            return;
        }

        $jobModel = config('taskbridge.models.scheduled_job', ScheduledJob::class);
        $class = get_class($job);
        $record = $jobModel::where('class', $class)->first();

        if (! $record) {
            $next($job);

            return;
        }

        $logging = config('taskbridge.logging.enabled', true);
        $runModel = config('taskbridge.models.scheduled_job_run', ScheduledJobRun::class);

        // When logging is disabled, still honour enabled/shouldRun but skip DB writes
        if (! $logging) {
            if (! $record->enabled) {
                return;
            }
            if ($job instanceof ConditionalJob && ! $job->shouldRun()) {
                return;
            }
            $next($job);

            return;
        }

        $run = $runModel::create([
            'scheduled_job_id' => $record->id,
            'status' => RunStatus::Running,
            'triggered_by' => TriggeredBy::Scheduler,
            'started_at' => now(),
            'created_at' => now(),
        ]);

        Event::dispatch(new JobExecutionStarted($record, $run));

        // Check enabled state
        if (! $record->enabled) {
            $this->markSkipped($record, $run, 'Job is disabled');

            return;
        }

        // Check ConditionalJob
        if ($job instanceof ConditionalJob && ! $job->shouldRun()) {
            $this->markSkipped($record, $run, 'shouldRun() returned false');

            return;
        }

        // Count sub-job dispatches
        $dispatchCount = 0;
        $listener = function () use (&$dispatchCount) {
            $dispatchCount++;
        };

        Event::listen(JobQueued::class, $listener);

        $startTime = microtime(true);

        try {
            $next($job);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            Event::forget(JobQueued::class);

            $jobOutput = JobOutputRegistry::retrieveSuccess(get_class($job));

            $run->update([
                'status' => RunStatus::Succeeded,
                'finished_at' => now(),
                'duration_ms' => $durationMs,
                'jobs_dispatched' => $dispatchCount,
                'output' => $jobOutput?->toArray(),
            ]);

            $record->update([
                'last_run_at' => now(),
                'last_status' => RunStatus::Succeeded,
            ]);

            Event::dispatch(new JobExecutionSucceeded($record, $run->fresh()));
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            Event::forget(JobQueued::class);

            $run->update([
                'status' => RunStatus::Failed,
                'finished_at' => now(),
                'duration_ms' => $durationMs,
                'jobs_dispatched' => $dispatchCount,
                'output' => JobOutputRegistry::retrieveError(get_class($job), $e->getMessage())->toArray(),
            ]);

            $record->update([
                'last_run_at' => now(),
                'last_status' => RunStatus::Failed,
            ]);

            Event::dispatch(new JobExecutionFailed($record, $run->fresh(), $e));

            throw $e;
        }
    }

    private function markSkipped(ScheduledJob $record, ScheduledJobRun $run, string $reason): void
    {
        $run->update([
            'status' => RunStatus::Skipped,
            'finished_at' => now(),
            'duration_ms' => 0,
            'skipped_reason' => $reason,
        ]);

        $record->update([
            'last_run_at' => now(),
            'last_status' => RunStatus::Skipped,
        ]);

        Event::dispatch(new JobExecutionSkipped($record, $run->fresh(), $reason));
    }
}
