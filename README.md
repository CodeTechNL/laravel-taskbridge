# laravel-taskbridge

A **database-driven scheduler** that runs your Laravel jobs through **AWS EventBridge Scheduler** and SQS — without needing a server running around the clock.

Laravel's built-in scheduler still works alongside this package. TaskBridge is an addition, not a replacement: use it for jobs that should be triggered by EventBridge so you are not dependent on a continuously running server or `schedule:run` cron for those tasks. Jobs are stored in your database, synced to EventBridge, and dispatched into SQS at the configured time. The queue worker picks them up and processes them as normal Laravel jobs.

## Features

- **No `schedule:run` dependency** — EventBridge fires jobs directly onto SQS; your server does not need to run continuously
- **Database-driven** — every registered job is stored in `taskbridge_jobs`; history in `taskbridge_job_runs`
- **Recurring schedules** — standard 5-part cron or 6-part AWS cron expression
- **One-time schedules** — dispatch a job once at a specific date/time via `scheduleOnce()`; the record is kept in the database for visibility and purged automatically by `PruneOnceSchedulesJob`
- **Scalar constructor arguments** — `bool`, `int`, `float`, `string` (and nullable variants) are serialized into the SQS payload at schedule-creation time and reconstructed by the queue worker
- **Timezone-aware** — cron expressions are sent with `ScheduleExpressionTimezone` matching `config('app.timezone')`; one-time `at()` expressions are always converted to UTC
- **Full run history** — every execution logged with status, duration, trigger type, sub-jobs dispatched, and structured output
- **Enable / disable** — toggle a schedule on/off without deleting it; disabled schedules are removed from EventBridge but kept in the database
- **Runtime conditions** — implement `RunsConditionally` to skip execution based on application state
- **Structured output** — implement `ReportsTaskOutput` to log per-run metadata (rows processed, records skipped, etc.)
- **Domain events** — hook into the execution lifecycle via Laravel events
- **Built-in maintenance jobs** — `PruneRunsJob` and `PruneOnceSchedulesJob` ship with the package
- **Custom models** — extend the default models for your own scopes and relations
- **Predefined schedules** — define jobs and their cron expressions in `config/taskbridge.php` and import them with a single artisan command

## How it works

```
Your job class (implements ShouldQueue)
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

## Admin UI

A Filament admin panel integration is available as a separate package:

| Package | Filament version | Status |
|---------|-----------------|--------|
| [`codetechnl/laravel-taskbridge-filament-3`](https://packagist.org/packages/codetechnl/laravel-taskbridge-filament-3) | Filament v3 | Available |
| `codetechnl/laravel-taskbridge-filament-4` | Filament v4 | Coming soon |

The Filament package adds a full CRUD interface for managing scheduled jobs, viewing run history, triggering manual executions, and configuring constructor arguments — all without touching AWS directly. See its [README](../taskbridge-filament-3/README.md) for installation and configuration details.

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

EventBridge Scheduler needs an IAM role to put messages on your SQS queue. This role is **separate** from your application's AWS credentials — even admin credentials cannot bypass it.

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

> **Using CDK?** CDK can create and wire up this role automatically. Pass the generated `role.roleArn` output as `TASKBRIDGE_SCHEDULER_ROLE_ARN`.

### 2. EventBridge schedule group

Create a schedule group (or use the default `default` group):

```bash
aws scheduler create-schedule-group --name taskbridge
```

### 3. Environment variables

```dotenv
AWS_DEFAULT_REGION=eu-west-1

TASKBRIDGE_SCHEDULER_ROLE_ARN=arn:aws:iam::123456789012:role/taskbridge-scheduler-role
TASKBRIDGE_SCHEDULE_GROUP=taskbridge
TASKBRIDGE_SCHEDULE_PREFIX=taskbridge
```

## Creating a scheduled job

Any standard Laravel `ShouldQueue` job can be used with TaskBridge. No extra interface required:

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendDailyReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // your logic here
    }
}
```

### Predefined cron expression

Implement `HasPredefinedCronExpression` to bake a default cron expression into the job class. The Filament UI and `sync()` will pre-fill it automatically:

```php
use CodeTechNL\TaskBridge\Contracts\HasPredefinedCronExpression;

class SendDailyReport implements HasPredefinedCronExpression, ShouldQueue
{
    public function cronExpression(): string
    {
        return '0 8 * * *'; // every day at 08:00
    }

    public function handle(): void { ... }
}
```

Alternatively, set the cron directly on the `#[SchedulableJob]` attribute:

```php
#[SchedulableJob(cron: '0 8 * * *')]
class SendDailyReport implements ShouldQueue { ... }
```

The interface and attribute are both optional. If neither is set, the cron must be configured manually when creating the job record in the UI — useful when the schedule differs per environment.

The priority order is: `#[SchedulableJob(cron:)]` → `HasPredefinedCronExpression::cronExpression()` → set manually in the UI.

### Constructor arguments

Jobs with scalar constructor parameters (`bool`, `int`, `float`, `string`, or nullable variants) are fully supported. TaskBridge discovers them automatically and the Filament UI renders an input field for each parameter.

```php
use CodeTechNL\TaskBridge\Contracts\HasPredefinedCronExpression;

class GenerateReport implements HasPredefinedCronExpression, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $type,
        public readonly int    $rows    = 1000,
        public readonly bool   $dryRun  = false,
    ) {}

    public function cronExpression(): string { return '0 6 * * 1'; }

    public function handle(): void { /* uses $this->type, $this->rows, $this->dryRun */ }
}
```

The argument values are **baked into the serialized SQS payload** at the time the EventBridge schedule is created (or at the time a one-time schedule is set up). This means the job is reconstructed with the exact values you configured when the queue worker processes it — the arguments survive the full EventBridge → SQS → queue worker round-trip.

Jobs whose constructors require non-scalar arguments (e.g. Eloquent models, service objects) are **excluded from registration** — those values cannot be serialized into a static EventBridge payload. They are still surfaced in the Filament job picker as non-selectable cards with an explanation of which parameters are incompatible.

> **PHP constructor rule:** Required parameters must come before optional ones. A parameter is optional only when it has a declared default value and all parameters after it also have default values. Violating this order causes PHP to silently strip the default value, making the parameter required.

#### Nullable parameters

There are two distinct nullable cases:

**Required nullable** — no default value, but accepts `null`. The Filament UI renders the field as **required**. Submitting it empty sends `null` to the job.

```php
public function __construct(
    public readonly ?string $recipient,  // required — must be set (or explicitly null)
) {}
```

**Optional nullable** — has a default of `null`. The Filament UI renders the field as optional with a helper text hint. Leave it empty and the job receives `null` and can fall back to its own default logic:

```php
public function __construct(
    public readonly ?int $retentionDays = null,  // optional — leave blank to use config default
) {}

public function handle(): void
{
    $days = $this->retentionDays ?? (int) config('taskbridge.logging.retention_days', 30);
    // ...
}
```

The distinction matters in the UI: required nullable fields are validated as required; optional nullable fields show a "Leave empty to use the application default" hint.

## Optional interfaces

All optional interfaces follow Laravel's naming conventions and are self-describing.

> **Tip:** When using `#[SchedulableJob]` you can set `name`, `group`, and `cron` directly on the attribute instead of implementing the corresponding interface. The attribute takes precedence when both are present.

### `HasCustomLabel` — display name

Without this interface (or the `name` attribute parameter), TaskBridge auto-derives a readable label from the class name:
`SendDailyReport` → `"Send daily report"`.

```php
use CodeTechNL\TaskBridge\Contracts\HasCustomLabel;

class SendDailyReport implements HasCustomLabel, ShouldQueue
{
    public function taskLabel(): string
    {
        return 'Daily Report — Finance';
    }
}
```

### `HasGroup` — group in the UI

Without this interface (or the `group` attribute parameter), TaskBridge auto-detects the group from the job's folder:
`App\Jobs\Reporting\SendDailyReport` → group `"Reporting"`.

```php
use CodeTechNL\TaskBridge\Contracts\HasGroup;

class SendDailyReport implements HasGroup, ShouldQueue
{
    public function group(): string
    {
        return 'Reporting'; // Overrides the folder-based detection.
    }
}
```

### `SkipOnMaintenance` — skip when the application is down

Include this trait to automatically skip execution whenever the application is in maintenance mode. No configuration required — the run is recorded as `Skipped` in the history.

```php
use CodeTechNL\TaskBridge\Concerns\SkipOnMaintenance;

class SendDailyReport implements ShouldQueue
{
    use SkipOnMaintenance;

    public function handle(): void { ... }
}
```

TaskBridge checks `shouldSkipInMaintenanceMode()` **before** evaluating `RunsConditionally`, so a job with both will short-circuit on maintenance without ever calling `shouldRun()`. To add a custom runtime condition on top, implement `RunsConditionally` alongside the trait:

```php
use CodeTechNL\TaskBridge\Concerns\SkipOnMaintenance;
use CodeTechNL\TaskBridge\Contracts\RunsConditionally;

class SendDailyReport implements RunsConditionally, ShouldQueue
{
    use SkipOnMaintenance;

    public function shouldRun(): bool
    {
        return $this->someCondition(); // only checked when not in maintenance
    }
}
```

### `RunsConditionally` — runtime skip condition

```php
use CodeTechNL\TaskBridge\Contracts\RunsConditionally;

class SendDailyReport implements RunsConditionally, ShouldQueue
{
    public function shouldRun(): bool
    {
        return $this->someCondition();
    }
}
```

Return `false` to skip execution. The run is recorded as `Skipped` in the history.

### `ReportsTaskOutput` — log structured output

```php
use CodeTechNL\TaskBridge\Concerns\HasJobOutput;
use CodeTechNL\TaskBridge\Contracts\ReportsTaskOutput;

class ImportProducts implements ReportsTaskOutput, ShouldQueue
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

TaskBridge supports three ways to register jobs:

### 1. Auto-discovery — interface mode (default)

Scans directories and registers every non-abstract `ShouldQueue` job with a scalar constructor:

```php
// config/taskbridge.php
'auto_discovery' => [
    'mode'  => 'interface',   // default
    'paths' => [
        app_path('Jobs'),
    ],
],
```

Subdirectories are scanned recursively. Jobs in `app/Jobs/Reporting/` are automatically grouped under `"Reporting"` unless they implement `HasGroup` or carry `#[SchedulableJob(group:)]`.

### 2. Auto-discovery — attribute mode

Only registers classes that carry the `#[SchedulableJob]` attribute. The `ShouldQueue` check is skipped; the attribute itself is the discovery gate. Useful when you want explicit opt-in instead of registering every queued job in the folder:

```php
'auto_discovery' => [
    'mode'  => 'attribute',
    'paths' => [
        app_path('Jobs'),
    ],
],
```

```php
use CodeTechNL\TaskBridge\Attributes\SchedulableJob;

#[SchedulableJob(name: 'Daily Report', group: 'Reporting', cron: '0 8 * * *')]
class SendDailyReport implements ShouldQueue
{
    // ...
}
```

### 3. Manual registration

Disable discovery entirely and list jobs explicitly — useful for jobs from vendor packages or when you want full control:

```php
'auto_discovery' => [
    'mode'  => null,   // discovery disabled
    'paths' => [],
],

'jobs' => [
    \App\Jobs\SendDailyReport::class,
    \Vendor\Package\Jobs\SomeScheduledJob::class,
],
```

The `jobs` array is always merged with discovered jobs, regardless of mode. Use it to add individual jobs from outside the scanned paths even when discovery is active:

```php
'auto_discovery' => [
    'mode'  => 'interface',
    'paths' => [app_path('Jobs')],
],

'jobs' => [
    \Vendor\Package\Jobs\SomeScheduledJob::class,
],
```

### `#[SchedulableJob]` attribute

The attribute can carry optional metadata that takes precedence over the equivalent interfaces:

```php
#[SchedulableJob(
    name:  'Daily Finance Report',   // overrides HasCustomLabel::taskLabel()
    group: 'Finance',                // overrides HasGroup::group()
    cron:  '0 8 * * 1-5',           // overrides HasPredefinedCronExpression::cronExpression()
)]
class SendDailyReport implements ShouldQueue { ... }
```

All three parameters are optional. Omitting them falls back to the corresponding interface, then to auto-derived defaults. You may use `#[SchedulableJob]` with no arguments purely as a discovery marker while keeping existing interface implementations unchanged.

## Predefined schedules

You can define jobs and their configuration directly in `config/taskbridge.php` under the `schedules` key, then import them into the database with a single artisan command:

```php
// config/taskbridge.php
'schedules' => [
    \App\Jobs\SendDailyReport::class => [
        'cron'      => '0 8 * * *',
        'arguments' => [],
    ],
    \App\Jobs\GenerateReport::class => [
        'cron'      => '0 6 * * 1',
        'arguments' => ['weekly', 500, false],
    ],
],
```

Run the import command to upsert all entries into the database:

```bash
php artisan taskbridge:import-schedules
```

The command validates each entry before importing. Invalid entries are skipped (with a warning) and processing continues with the rest. The command returns a non-zero exit code if any entry failed validation.

**Validation rules:**
- Each entry must be an array with a `cron` key — plain-string shorthand is not accepted
- The class must exist and have a scalar-only constructor
- The cron expression must be valid (5-part or 6-part AWS format)
- The `arguments` array must match the number of constructor parameters

> **Note:** Each entry is an upsert. Running the command a second time updates existing records rather than creating duplicates.

## Task name prefix

Job identifiers are prefixed to avoid collisions across environments. The default is the slugified `APP_ENV` value:

```
APP_ENV=production       → identifier: production-send-daily-report
APP_ENV=staging          → identifier: staging-send-daily-report
```

Override via `TASKBRIDGE_NAME_PREFIX` or set to `null` to disable:

```dotenv
TASKBRIDGE_NAME_PREFIX=myapp
```

### 64-character identifier limit

EventBridge schedule names have a maximum length. TaskBridge enforces a **64-character limit** on generated identifiers:

- If `prefix + "-" + kebab-class-name` exceeds 64 characters, the bare class-name part is replaced with its `md5()` hash (the prefix is **not** included in the hash input).
- If `prefix + "-" + md5` would still exceed 64 characters (i.e. the prefix alone is longer than 31 characters), a `RuntimeException` is thrown.

This means prefixes up to 31 characters are always safe. Prefixes longer than 31 characters may cause an exception if the identifier would still overflow after md5 substitution.

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

// Pass constructor arguments
$run = TaskBridge::run(GenerateReport::class, force: true, arguments: ['weekly', 500, false]);
```

### One-time scheduling

Schedule a job to run once at a specific future time via EventBridge. The EventBridge schedule self-destructs after firing. A record is stored in `taskbridge_jobs` (`run_once_at` is set) so the scheduled time is visible in the UI until the row is pruned.

```php
use Carbon\Carbon;

TaskBridge::scheduleOnce(GenerateReport::class, Carbon::parse('2024-06-01 09:00'));

// With constructor arguments
TaskBridge::scheduleOnce(GenerateReport::class, Carbon::parse('2024-06-01 09:00'), ['annual', 5000]);
```

The datetime must be in the future. It is interpreted in `config('app.timezone')` and converted to UTC for the EventBridge `at()` expression.

## Enable / disable jobs

```php
TaskBridge::enable(SendDailyReport::class);  // enables + syncs to EventBridge
TaskBridge::disable(SendDailyReport::class); // disables + removes from EventBridge
```

## Cron override

Override the cron expression without editing the job class — useful for environment-specific schedules:

```php
TaskBridge::overrideCron(SendDailyReport::class, '*/15 * * * *');
TaskBridge::resetCron(SendDailyReport::class); // restore original
```

Both 5-part standard cron (`minute hour dom month dow`) and 6-part AWS format (`minute hour dom month dow year`) are accepted.

## Configuration reference

```php
// config/taskbridge.php

return [
    'models' => [
        // Override with your own model if needed (must extend the original)
        'scheduled_job'     => \CodeTechNL\TaskBridge\Models\ScheduledJob::class,
        'scheduled_job_run' => \CodeTechNL\TaskBridge\Models\ScheduledJobRun::class,
    ],

    // Prefix applied to all job identifiers. Defaults to slugified APP_ENV.
    'name_prefix' => env('TASKBRIDGE_NAME_PREFIX', Str::slug(env('APP_ENV', 'local'))),

    'eventbridge' => [
        'region'         => env('AWS_DEFAULT_REGION', 'eu-west-1'),
        'prefix'         => env('TASKBRIDGE_SCHEDULE_PREFIX', 'taskbridge'),
        'role_arn'       => env('TASKBRIDGE_SCHEDULER_ROLE_ARN'),
        'schedule_group' => env('TASKBRIDGE_SCHEDULE_GROUP', 'default'),
        'retry_policy'   => [
            'maximum_event_age_seconds' => env('TASKBRIDGE_RETRY_MAX_AGE_SECONDS', 86400),
            'maximum_retry_attempts'    => env('TASKBRIDGE_RETRY_MAX_ATTEMPTS', 185),
        ],
    ],

    'auto_discovery' => [
        // 'interface' — register every ShouldQueue job with a scalar constructor (default)
        // 'attribute' — register only classes carrying #[SchedulableJob]
        // null        — disable discovery; use 'jobs' array only
        'mode'  => env('TASKBRIDGE_DISCOVERY_MODE', 'interface'),
        'paths' => [
            app_path('Jobs'),
        ],
    ],

    'jobs' => [],

    // Predefined schedules — imported via `php artisan taskbridge:import-schedules`
    // Each entry must be an array with a 'cron' key. Plain-string shorthand is not supported.
    'schedules' => [
        // \App\Jobs\SendDailyReport::class => [
        //     'cron'      => '0 8 * * *',
        //     'arguments' => [],
        // ],
    ],

    'logging' => [
        'enabled'        => env('TASKBRIDGE_LOGGING_ENABLED', true),
        'retention_days' => env('TASKBRIDGE_RUN_RETENTION_DAYS', 30),
    ],
];
```

## Built-in maintenance jobs

All three built-in jobs are listed in `config/taskbridge.php` under `jobs`. `PruneRunsJob` is enabled by default; uncomment the others as needed.

### PruneRunsJob

Deletes run log entries older than a configurable retention window. Runs daily at 03:00.

The retention period is resolved in this order:
1. The `$retentionDays` constructor argument — configured via the Filament UI when creating or editing the job record
2. `taskbridge.logging.retention_days` config value
3. Hard-coded fallback of 30 days

### PruneOnceSchedulesJob

Deletes `taskbridge_jobs` rows where `run_once_at` is set and the scheduled time is older than the configured retention window. Keeps the table clean after one-time schedules have fired. Runs daily at 03:00.

Constructor argument `?int $retentionDays = null` — configure per-environment in the UI, or falls back to `taskbridge.logging.retention_days`.

### CheckMissedJobs

Monitors jobs that haven't run within twice their expected cron interval and dispatches a `JobMissed` event. Runs every hour. Listen to this event to send alerts.

Requires `taskbridge.monitoring.notify_on_miss` to be `true` to take effect.

## Events

Listen to these events to add custom behaviour:

```php
use CodeTechNL\TaskBridge\Events\JobExecutionFailed;

Event::listen(JobExecutionFailed::class, function (JobExecutionFailed $event) {
    // $event->job       — ScheduledJob model
    // $event->run       — ScheduledJobRun model
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

---

*This package was fully built with [Claude Code](https://claude.ai/claude-code).*
