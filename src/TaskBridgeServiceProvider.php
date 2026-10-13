<?php

namespace CodeTechNL\TaskBridge;

use CodeTechNL\TaskBridge\Commands\ImportSchedulesCommand;
use CodeTechNL\TaskBridge\Drivers\EventBridgeDriver;
use CodeTechNL\TaskBridge\Enums\RunStatus;
use CodeTechNL\TaskBridge\Enums\TriggeredBy;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Models\ScheduledJobRun;
use CodeTechNL\TaskBridge\Support\JobDiscoverer;
use CodeTechNL\TaskBridge\Support\JobOutputRegistry;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\ServiceProvider;

class TaskBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/taskbridge.php',
            'taskbridge'
        );

        $this->app->singleton(EventBridgeDriver::class, function () {
            return new EventBridgeDriver(config('taskbridge.eventbridge', []));
        });

        $this->app->singleton(TaskBridge::class, function ($app) {
            return new TaskBridge($app->make(EventBridgeDriver::class));
        });

        $this->app->alias(TaskBridge::class, 'taskbridge');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'taskbridge');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/taskbridge'),
        ], 'taskbridge-lang');

        $this->publishes([
            __DIR__.'/../config/taskbridge.php' => config_path('taskbridge.php'),
        ], 'taskbridge-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'taskbridge-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerJobsFromConfig();
        $this->registerQueueListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([ImportSchedulesCommand::class]);
        }
    }

    private function registerJobsFromConfig(): void
    {
        $mode = config('taskbridge.auto_discovery.mode', 'interface');
        $paths = config('taskbridge.auto_discovery.paths', []);

        $discovered = match (true) {
            $mode === 'attribute' => JobDiscoverer::discoverByAttribute($paths),
            $mode === 'interface' => JobDiscoverer::discover($paths),
            default => [], // null / false / unknown — discovery disabled
        };

        $manual = config('taskbridge.jobs', []);

        $all = array_unique(array_merge($discovered, $manual));

        if (empty($all)) {
            return;
        }

        $this->app->make(TaskBridge::class)->register($all);
    }

    /**
     * Hook into Laravel's queue events to log scheduler-triggered runs automatically.
     *
     * This fires for every job processed by the queue worker, so no changes are
     * needed to individual job classes. Only jobs that are registered in TaskBridge
     * and have a record in the database are tracked.
     *
     * The SQS payload EventBridge delivers names the schedule that fired it
     * (EventBridgeDriver::PAYLOAD_SCHEDULE_KEY). A one-time schedule is tracked
     * on its own taskbridge_jobs row and continues the pending run that
     * scheduleOnce() wrote; a recurring schedule is tracked on the row whose
     * identifier it is.
     *
     * Manual runs (via TaskBridge::run()) call handle() directly and are logged
     * separately, so they do not go through these listeners.
     */
    private function registerQueueListeners(): void
    {
        if (! config('taskbridge.logging.enabled', true)) {
            return;
        }

        // Keyed by job UUID to correlate before/after events
        $tracking = [];

        $jobModel = config('taskbridge.models.scheduled_job', ScheduledJob::class);
        $runModel = config('taskbridge.models.scheduled_job_run', ScheduledJobRun::class);

        $this->app['events']->listen(JobProcessing::class, function (JobProcessing $event) use (&$tracking, $jobModel, $runModel) {
            $payload = $event->job->payload();
            $class = $payload['data']['commandName'] ?? null;

            if (! $class || ! $this->app->make(TaskBridge::class)->isRegistered($class)) {
                return;
            }

            $record = $this->resolveScheduledJob($jobModel, $class, $payload[EventBridgeDriver::PAYLOAD_SCHEDULE_KEY] ?? null);
            if (! $record) {
                return;
            }

            $uuid = $payload['uuid'] ?? uniqid();
            $run = $this->startRun($runModel, $record);

            $dispatchCount = 0;
            $queuedListener = function () use (&$dispatchCount) {
                $dispatchCount++;
            };

            $this->app['events']->listen(JobQueued::class, $queuedListener);

            $tracking[$uuid] = [
                'run' => $run,
                'startTime' => microtime(true),
                'record' => $record,
                'class' => $class,
                'dispatchCount' => &$dispatchCount,
                'queuedListener' => $queuedListener,
            ];
        });

        $this->app['events']->listen(JobProcessed::class, function (JobProcessed $event) use (&$tracking) {
            $uuid = $event->job->payload()['uuid'] ?? null;
            if (! isset($tracking[$uuid])) {
                return;
            }

            ['run' => $run, 'startTime' => $startTime, 'record' => $record, 'class' => $class, 'dispatchCount' => $dispatchCount, 'queuedListener' => $queuedListener] = $tracking[$uuid];
            unset($tracking[$uuid]);

            $this->app['events']->forget(JobQueued::class);

            $run->update([
                'status' => RunStatus::Succeeded,
                'finished_at' => now(),
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'jobs_dispatched' => $dispatchCount,
                'output' => $this->mergeOutput($run->output, JobOutputRegistry::retrieveSuccess($class)?->toArray()),
            ]);

            $record->update([
                'last_run_at' => now(),
                'last_status' => RunStatus::Succeeded,
            ]);
        });

        $this->app['events']->listen(JobFailed::class, function (JobFailed $event) use (&$tracking) {
            $uuid = $event->job->payload()['uuid'] ?? null;
            if (! isset($tracking[$uuid])) {
                return;
            }

            ['run' => $run, 'startTime' => $startTime, 'record' => $record, 'class' => $class, 'dispatchCount' => $dispatchCount] = $tracking[$uuid];
            unset($tracking[$uuid]);

            $this->app['events']->forget(JobQueued::class);

            $run->update([
                'status' => RunStatus::Failed,
                'finished_at' => now(),
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'jobs_dispatched' => $dispatchCount,
                'output' => $this->mergeOutput($run->output, JobOutputRegistry::retrieveError($class, $event->exception->getMessage())->toArray()),
            ]);

            $record->update([
                'last_run_at' => now(),
                'last_status' => RunStatus::Failed,
            ]);
        });
    }

    /**
     * The taskbridge_jobs row a fired job is tracked on.
     *
     * A one-time schedule name maps to its own row (run_once_schedule_name)
     * and to nothing else: a one-time schedule without a row was created with
     * scheduleOnce(..., log: false) and is not logged. A recurring schedule
     * name maps to the row whose identifier it is. A payload without a name
     * (a schedule created before the key existed, or a job the application
     * dispatched itself) falls back to the class, recurring row first, so a
     * one-time row for the same class never absorbs the recurring runs.
     *
     * @param  class-string<ScheduledJob>  $jobModel
     */
    private function resolveScheduledJob(string $jobModel, string $class, ?string $scheduleName): ?ScheduledJob
    {
        if ($scheduleName !== null && EventBridgeDriver::isOnceScheduleName($scheduleName)) {
            return $jobModel::where('run_once_schedule_name', $scheduleName)->first();
        }

        if ($scheduleName !== null) {
            $record = $jobModel::recurring()->where('identifier', $scheduleName)->first();

            if ($record) {
                return $record;
            }
        }

        return $jobModel::recurring()->where('class', $class)->first()
            ?? $jobModel::where('class', $class)->first();
    }

    /**
     * The run that tracks a fired job: for a one-time row the pending run
     * scheduleOnce() wrote, moved to Running; otherwise a new Running run.
     *
     * @param  class-string<ScheduledJobRun>  $runModel
     */
    private function startRun(string $runModel, ScheduledJob $record): ScheduledJobRun
    {
        if ($record->isOnce()) {
            $pending = $runModel::where('scheduled_job_id', $record->id)
                ->where('status', RunStatus::Pending)
                ->first();

            if ($pending) {
                $pending->update(['status' => RunStatus::Running, 'started_at' => now()]);

                return $pending;
            }
        }

        return $runModel::create([
            'scheduled_job_id' => $record->id,
            'status' => RunStatus::Running,
            'triggered_by' => $record->isOnce() ? TriggeredBy::ScheduledOnce : TriggeredBy::Scheduler,
            'scheduled_for' => $record->run_once_at,
            'started_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * The job's reported output on top of what the run already held, so the
     * bookkeeping a pending run carries (job_class, aws_schedule_name)
     * survives its completion.
     *
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>|null  $reported
     * @return array<string, mixed>|null
     */
    private function mergeOutput(?array $existing, ?array $reported): ?array
    {
        if ($existing === null) {
            return $reported;
        }

        return array_merge($existing, $reported ?? []);
    }
}
