# laravel-taskbridge

A **database-driven scheduler** that runs your Laravel jobs through **AWS EventBridge Scheduler** and SQS — without needing a server running around the clock.

Laravel's built-in scheduler still works alongside this package. TaskBridge is an addition, not a replacement: use it for jobs that should be triggered by EventBridge so you are not dependent on a continuously running server or `schedule:run` cron for those tasks. Jobs are stored in your database, synced to EventBridge, and dispatched into SQS at the configured time. The queue worker picks them up and processes them as normal Laravel jobs.

## How it works

```
Your job class (implements ScheduledJob)
        │
        ▼
  TaskBridge::sync()
        │  stores job in database + creates/updates schedule in AWS EventBridge
        ▼
  AWS EventBridge Scheduler
        │  fires at the configured cron time
        │  puts a raw SQS message on your queue
        ▼
  Your SQS queue worker (php artisan queue:work)
        │  picks up the message and executes the job
        │  TaskBridge middleware wraps execution
        ▼
  Run log (taskbridge_job_runs)
        │  status, duration, output, triggered_by
        ▼
  Domain events (JobExecutionSucceeded / JobExecutionFailed / …)
```

Your existing Laravel scheduler (`schedule:run`) keeps working unchanged. TaskBridge adds a separate path for jobs that should be triggered by EventBridge, removing the requirement for a continuously running server or cron daemon for those specific tasks.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- AWS account with EventBridge Scheduler and SQS access

## Installation

```bash
composer require codetechnl/laravel-taskbridge
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=taskbridge-migrations
php artisan migrate
```

Publish the config file:

```bash
php artisan vendor:publish --tag=taskbridge-config
```

## AWS setup

### 1. IAM execution role

EventBridge Scheduler needs an IAM role to put messages on your SQS queue. This role is **separate** from your application's AWS credentials.

Create the role with an SQS trust policy and `sqs:SendMessage` permission:

```bash
# Trust policy
aws iam create-role \
  --role-name taskbridge-scheduler-role \
  --assume-role-policy-document '{
    "Version":"2012-10-17",
    "Statement":[{
      "Effect":"Allow",
      "Principal":{"Service":"scheduler.amazonaws.com"},
      "Action":"sts:AssumeRole"
    }]
  }'

# Permission policy
aws iam put-role-policy \
  --role-name taskbridge-scheduler-role \
  --policy-name taskbridge-sqs-send \
  --policy-document '{
    "Version":"2012-10-17",
    "Statement":[{
      "Effect":"Allow",
      "Action":"sqs:SendMessage",
      "Resource":"arn:aws:sqs:*:*:*"
    }]
  }'
```

### 2. EventBridge schedule group

Create a schedule group (or use the default `default` group):

```bash
aws scheduler create-schedule-group --name taskbridge
```

### 3. Environment variables

```dotenv
AWS_DEFAULT_REGION=eu-west-1

# Optional — omit when using CDK or another tool that attaches the role directly to the schedule target
TASKBRIDGE_SCHEDULER_ROLE_ARN=arn:aws:iam::123456789012:role/taskbridge-scheduler-role
TASKBRIDGE_SCHEDULE_GROUP=taskbridge
TASKBRIDGE_SCHEDULE_PREFIX=taskbridge
```

## Creating a scheduled job

A scheduled job is a standard Laravel `ShouldQueue` job that also implements the `ScheduledJob` contract:

```php
use CodeTechNL\TaskBridge\Contracts\ScheduledJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendDailyReport implements ScheduledJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function cronExpression(): string
    {
        return '0 8 * * *'; // every day at 08:00
    }

    public function handle(): void
    {
        // your logic here
    }
}
```

## Optional interfaces

### LabeledJob — custom display name

```php
use CodeTechNL\TaskBridge\Contracts\LabeledJob;

class SendDailyReport implements ScheduledJob, LabeledJob, ShouldQueue
{
    public function taskLabel(): string
    {
        return 'Send Daily Report';
    }
    // ...
}
```

### GroupedJob — group jobs in the UI

```php
use CodeTechNL\TaskBridge\Contracts\GroupedJob;

class SendDailyReport implements ScheduledJob, GroupedJob, ShouldQueue
{
    public function group(): string
    {
        return 'Reporting';
    }
    // ...
}
```

### ConditionalJob — runtime skip condition

```php
use CodeTechNL\TaskBridge\Contracts\ConditionalJob;

class SendDailyReport implements ScheduledJob, ConditionalJob, ShouldQueue
{
    public function shouldRun(): bool
    {
        return ! app()->isMaintenanceMode();
    }
    // ...
}
```

### ReportsOutput — log structured output

```php
use CodeTechNL\TaskBridge\Concerns\HasJobOutput;
use CodeTechNL\TaskBridge\Contracts\ReportsOutput;

class ImportProducts implements ScheduledJob, ReportsOutput, ShouldQueue
{
    use HasJobOutput;

    public function handle(): void
    {
        $processed = 0;
        $skipped   = 0;

        // ... your import logic ...

        $this->reportOutput([
            'processed' => $processed,
            'skipped'   => $skipped,
        ]);
    }
}
```

The metadata is stored as a `success` `JobOutput` in the run log. On failure, TaskBridge automatically records an `error` output with the exception message — no action needed in the job.

## Job discovery

By default, TaskBridge scans `app/Jobs` for classes implementing `ScheduledJob`:

```php
// config/taskbridge.php
'discover' => [
    app_path('Jobs'),
],
```

To scan additional directories or register jobs from vendor packages manually:

```php
'discover' => [
    app_path('Jobs'),
    app_path('Modules/Billing/Jobs'),
],

'jobs' => [
    \Vendor\Package\Jobs\SomeScheduledJob::class,
],
```

## Syncing to EventBridge

After adding jobs to the database (via the Filament UI or manually), sync them to EventBridge:

```php
use CodeTechNL\TaskBridge\Facades\TaskBridge;

$result = TaskBridge::sync();
// $result->created, $result->updated, $result->removed
```

## Manual execution

```php
// Run immediately (bypasses enabled/shouldRun check)
$run = TaskBridge::run(SendDailyReport::class, force: true);

// Run with enabled/shouldRun checks
$run = TaskBridge::run(SendDailyReport::class);

// Dry run — calls handle() but Bus::fake() prevents actual queue dispatches
$run = TaskBridge::run(SendDailyReport::class, dryRun: true);
```

## Enable / disable jobs

```php
TaskBridge::enable(SendDailyReport::class);  // enables + syncs to EventBridge
TaskBridge::disable(SendDailyReport::class); // disables + removes from EventBridge
```

## Cron override

Temporarily override the cron expression without editing the job class:

```php
TaskBridge::overrideCron(SendDailyReport::class, '*/15 * * * *');
TaskBridge::resetCron(SendDailyReport::class); // restore to cronExpression()
```

## Configuration reference

```php
// config/taskbridge.php

return [
    'models' => [
        // Override with your own model if needed (must extend the original)
        'scheduled_job'     => \CodeTechNL\TaskBridge\Models\ScheduledJob::class,
        'scheduled_job_run' => \CodeTechNL\TaskBridge\Models\ScheduledJobRun::class,
    ],

    'eventbridge' => [
        'region'         => env('AWS_DEFAULT_REGION', 'eu-west-1'),
        'prefix'         => env('TASKBRIDGE_SCHEDULE_PREFIX', 'taskbridge'),
        'role_arn'       => env('TASKBRIDGE_SCHEDULER_ROLE_ARN'), // optional
        'schedule_group' => env('TASKBRIDGE_SCHEDULE_GROUP', 'default'),
        'retry_policy'   => [
            'maximum_event_age_seconds' => env('TASKBRIDGE_RETRY_MAX_AGE_SECONDS', 86400),
            'maximum_retry_attempts'    => env('TASKBRIDGE_RETRY_MAX_ATTEMPTS', 185),
        ],
    ],

    'discover' => [
        app_path('Jobs'),
    ],

    'jobs' => [],

    'logging' => [
        'enabled'        => env('TASKBRIDGE_LOGGING_ENABLED', true),
        'retention_days' => env('TASKBRIDGE_RUN_RETENTION_DAYS', 30),
    ],
];
```

## Built-in maintenance jobs

### PruneRunsJob

Deletes run log entries older than `taskbridge.logging.retention_days` (default: 30 days). Runs daily at 03:00.

Enable it by adding it to the `jobs` array in config:

```php
'jobs' => [
    \CodeTechNL\TaskBridge\Jobs\PruneRunsJob::class,
],
```

Then sync to register it in EventBridge.

### CheckMissedJobs

Monitors jobs that haven't run within twice their expected cron interval and dispatches a `JobMissed` event. Listen to this event to send alerts.

## Events

Listen to these events to add custom behaviour:

```php
use CodeTechNL\TaskBridge\Events\JobExecutionFailed;

Event::listen(JobExecutionFailed::class, function (JobExecutionFailed $event) {
    // $event->job   — ScheduledJob model
    // $event->run   — ScheduledJobRun model
    // $event->exception — Throwable
    Notification::send(...);
});
```

| Event | Payload |
|-------|---------|
| `JobExecutionStarted` | `$job`, `$run` |
| `JobExecutionSucceeded` | `$job`, `$run` |
| `JobExecutionFailed` | `$job`, `$run`, `$exception` |
| `JobExecutionSkipped` | `$job`, `$run`, `$reason` |
| `JobSynced` | `$job` |
| `JobRemoved` | identifier string |
| `JobMissed` | `$job` |

## Custom models

Extend and override the default models for custom logic:

```php
// app/Models/ScheduledJob.php
class ScheduledJob extends \CodeTechNL\TaskBridge\Models\ScheduledJob
{
    // custom scopes, relations, etc.
}
```

```php
// config/taskbridge.php
'models' => [
    'scheduled_job'     => App\Models\ScheduledJob::class,
    'scheduled_job_run' => App\Models\ScheduledJobRun::class,
],
```

## Translating status labels

Publish the language files:

```bash
php artisan vendor:publish --tag=taskbridge-lang
```

This creates `lang/vendor/taskbridge/en/enums.php`. Copy and translate for other locales.

## Running the tests

```bash
./vendor/bin/pest
```
