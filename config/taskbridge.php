<?php

use CodeTechNL\TaskBridge\Jobs\CheckMissedJobs;
use CodeTechNL\TaskBridge\Jobs\PruneOnceSchedulesJob;
use CodeTechNL\TaskBridge\Jobs\PruneRunsJob;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Models\ScheduledJobRun;
use Illuminate\Support\Str;

return [
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default Eloquent models used by TaskBridge.
    | Your custom models must extend the originals.
    |
    */
    'models' => [
        'scheduled_job' => ScheduledJob::class,
        'scheduled_job_run' => ScheduledJobRun::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Name Prefix
    |--------------------------------------------------------------------------
    |
    | An optional prefix applied to every job identifier.
    | Useful for distinguishing jobs across multiple apps or environments.
    |
    | Defaults to the APP_ENV value, slugified and lowercased.
    | e.g. "production"        → "production"
    |      "Production Donny"  → "production-donny"
    |
    | Override via TASKBRIDGE_NAME_PREFIX or set to null to disable.
    |
    */
    'name_prefix' => env('TASKBRIDGE_NAME_PREFIX', Str::slug(env('APP_ENV', 'local'))),

    /*
    |--------------------------------------------------------------------------
    | EventBridge Scheduler
    |--------------------------------------------------------------------------
    |
    | TaskBridge uses AWS EventBridge Scheduler to manage cron schedules.
    |
    | role_arn    — IAM role EventBridge Scheduler assumes to publish to SQS.
    |               Required by the AWS API. The role must trust scheduler.amazonaws.com
    |               and have sqs:SendMessage on the target queue.
    |               When using CDK, pass the ARN from your stack output.
    |
    | schedule_group — All schedules are placed in this group.
    |               Create the group in the AWS console before syncing.
    |               Defaults to the AWS-managed "default" group.
    |
    | retry_policy — Applied to every schedule target.
    |               maximum_event_age_seconds: 60–86400 (default 86400 = 24 h)
    |               maximum_retry_attempts:    0–185    (default 185)
    |
    */
    'eventbridge' => [
        'region' => env('AWS_DEFAULT_REGION', 'eu-west-1'),
        'prefix' => env('TASKBRIDGE_SCHEDULE_PREFIX', 'taskbridge'),
        'role_arn' => env('TASKBRIDGE_SCHEDULER_ROLE_ARN'),
        'schedule_group' => env('TASKBRIDGE_SCHEDULE_GROUP', 'default'),
        'retry_policy' => [
            'maximum_event_age_seconds' => env('TASKBRIDGE_RETRY_MAX_AGE_SECONDS', 86400),
            'maximum_retry_attempts' => env('TASKBRIDGE_RETRY_MAX_ATTEMPTS', 185),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Discovery
    |--------------------------------------------------------------------------
    |
    | TaskBridge will scan these directories and automatically register jobs.
    | The strategy used to identify which classes to register is controlled
    | by the discovery_mode option below.
    |
    | You can also manually list additional classes in the 'jobs' array below
    | (e.g. jobs from third-party packages that live outside your app paths).
    |
    | discovery_mode options:
    |
    |   'interface' (default) — Register every non-abstract class that
    |               implements Illuminate\Contracts\Queue\ShouldQueue and has
    |               a simple (scalar-only) constructor. This is the original
    |               TaskBridge behavior.
    |
    |   'attribute' — Register only classes that carry the
    |               #[SchedulableJob] attribute. The ShouldQueue check is
    |               skipped; the attribute is the discovery gate. This lets
    |               you opt individual jobs in explicitly instead of
    |               registering every queued job in the scanned folders.
    |               Set discovery_mode to 'attribute' and decorate each job:
    |
    |               #[SchedulableJob(name: 'Send Report', group: 'Reporting', cron: '0 6 * * *')]
    |               class SendReportJob implements ShouldQueue { ... }
    |
    |   null / false — Disable automatic discovery entirely. Only jobs listed
    |               in the 'jobs' array below are registered.
    |
    */
    'auto_discovery' => [
        'mode' => env('TASKBRIDGE_DISCOVERY_MODE', 'interface'),
        'paths' => [
            app_path('Jobs'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Additional Registered Jobs
    |--------------------------------------------------------------------------
    |
    | Manually register job classes that are not in a discovered path
    | (e.g. from vendor packages).
    |
    */
    'jobs' => [
        // Built-in maintenance jobs — uncomment any you want TaskBridge to schedule automatically.

        // Deletes run log entries older than logging.retention_days. Runs daily at 03:00.
        PruneRunsJob::class,

        // Deletes expired one-time schedule rows older than logging.retention_days. Runs daily at 03:00.
        // PruneOnceSchedulesJob::class,

        // Dispatches a JobMissed event for jobs that haven't run within twice their cron interval.
        // Requires taskbridge.monitoring.notify_on_miss to be true to take effect.
        // CheckMissedJobs::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Predefined Schedules
    |--------------------------------------------------------------------------
    |
    | Map job classes to cron expressions. Running `php artisan taskbridge:import-schedules`
    | will validate each entry and upsert it into the database as a recurring schedule.
    | Invalid entries are skipped and reported; the rest are still imported.
    |
    | Each entry must use the array format with 'cron' and 'arguments' keys.
    | Use an empty array for jobs with no constructor arguments.
    |
    |   \App\Jobs\CleanupJob::class => [
    |       'cron'      => '0 3 * * *',
    |       'arguments' => [],
    |   ],
    |   \App\Jobs\SendReportJob::class => [
    |       'cron'      => '0 9 * * 1',
    |       'arguments' => ['monthly', 500],
    |   ],
    |
    */
    'schedules' => [
        // \App\Jobs\YourJob::class => ['cron' => '* * * * *', 'arguments' => []],
    ],

    /*
    |--------------------------------------------------------------------------
    | Run Logging
    |--------------------------------------------------------------------------
    |
    | Set enabled to false to stop recording run history entirely.
    | retention_days controls how long PruneRunsJob keeps entries.
    |
    */
    'logging' => [
        'enabled' => env('TASKBRIDGE_LOGGING_ENABLED', true),
        'retention_days' => env('TASKBRIDGE_RUN_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'notify_on_miss' => env('TASKBRIDGE_NOTIFY_ON_MISS', false),
        'notify_on_failure' => env('TASKBRIDGE_NOTIFY_ON_FAILURE', false),
        'notification_channel' => env('TASKBRIDGE_NOTIFICATION_CHANNEL', 'mail'),
    ],
];
