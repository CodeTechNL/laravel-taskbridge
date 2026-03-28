<?php

use CodeTechNL\TaskBridge\Jobs\PruneRunsJob;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Models\ScheduledJobRun;

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
    | EventBridge Scheduler
    |--------------------------------------------------------------------------
    |
    | TaskBridge uses AWS EventBridge Scheduler to manage cron schedules.
    |
    | role_arn    — IAM role EventBridge Scheduler assumes to publish to SQS.
    |               The role must have sqs:SendMessage on the target queue.
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
    | TaskBridge will scan these directories and automatically register every
    | class that implements CodeTechNL\TaskBridge\Contracts\ScheduledJob.
    |
    | You can also manually list additional classes in the 'jobs' array below
    | (e.g. jobs from third-party packages that live outside your app paths).
    |
    | Classes may optionally implement:
    |   - LabeledJob     → taskLabel()   human-readable name in the UI
    |   - GroupedJob     → group()       groups jobs in the Filament dropdown
    |   - ConditionalJob → shouldRun()   runtime skip condition
    |
    */
    'discover' => [
        app_path('Jobs'),
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
        // Built-in maintenance job — prunes run logs older than logging.retention_days.
        // Uncomment to have TaskBridge schedule this automatically.
        PruneRunsJob::class,
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
