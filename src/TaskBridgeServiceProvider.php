<?php

namespace CodeTechNL\TaskBridge;

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
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'taskbridge');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'taskbridge');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/taskbridge'),
        ], 'taskbridge-lang');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/taskbridge'),
        ], 'taskbridge-views');

        $this->publishes([
            __DIR__.'/../config/taskbridge.php' => config_path('taskbridge.php'),
        ], 'taskbridge-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'taskbridge-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerJobsFromConfig();
        $this->registerQueueListeners();
    }

    private function registerJobsFromConfig(): void
    {
        $discovered = JobDiscoverer::discover(config('taskbridge.discover', []));
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

            $record = $jobModel::where('class', $class)->first();
            if (! $record) {
                return;
            }

            $uuid = $payload['uuid'] ?? uniqid();

            $run = $runModel::create([
                'scheduled_job_id' => $record->id,
                'status' => RunStatus::Running,
                'triggered_by' => TriggeredBy::Scheduler,
                'started_at' => now(),
                'created_at' => now(),
            ]);

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
                'output' => JobOutputRegistry::retrieveSuccess($class)?->toArray(),
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
                'output' => JobOutputRegistry::retrieveError($class, $event->exception->getMessage())->toArray(),
            ]);

            $record->update([
                'last_run_at' => now(),
                'last_status' => RunStatus::Failed,
            ]);
        });
    }
}
