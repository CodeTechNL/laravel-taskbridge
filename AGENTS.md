# AGENTS.md — laravel-taskbridge

For full context on this package, read @README.md. For architecture details, read @docs/architecture.md.

## Commands

Run after every code change — both must pass before finishing:

```bash
./vendor/bin/pint   # code style (default ruleset)
./vendor/bin/pest   # all tests must pass
```

If `vendor/` is missing, run `composer install` first.

## Git

**Never create commits unless explicitly requested by the user.**

## Rules

**This package is an addition, not a replacement.** It works alongside Laravel's built-in scheduler. Never describe or document it as a replacement for `Kernel::schedule()`.

**`ScheduledJob` interface does not exist.** Any `ShouldQueue` job can be used with TaskBridge. Discovery uses `ShouldQueue` (interface mode) or `#[SchedulableJob]` (attribute mode), the middleware checks `TaskBridge::isRegistered()`. Never re-introduce a `ScheduledJob` interface.

**Interface names are final.** The five optional interfaces are: `RunsConditionally`, `HasGroup`, `HasCustomLabel`, `ReportsTaskOutput`, `HasPredefinedCronExpression`. Do not use or reference the old names (`ConditionalJob`, `GroupedJob`, `LabeledJob`, `ReportsOutput`).

**`#[SchedulableJob]` attribute takes precedence over interfaces for label, group, and cron.** Priority order: attribute → interface → auto-derived default. When both are present the attribute wins. Omitting an attribute parameter falls through to the interface, then to the default. Never change this priority order.

**Discovery config lives under `auto_discovery`, not at the root.** The old flat keys `discovery_mode` and `discover` no longer exist. Always use:
```php
config('taskbridge.auto_discovery.mode')   // 'interface' | 'attribute' | null
config('taskbridge.auto_discovery.paths')  // array of directory paths
```

**`ReportsTaskOutput` requires `reportOutput()`.** The interface declares `reportOutput(array $metadata): void` — it is no longer a marker. The `HasJobOutput` trait satisfies it:
```php
class ImportProducts implements ReportsTaskOutput, ShouldQueue
{
    use HasJobOutput; // provides the required reportOutput() implementation
}
```

**`cronExpression()` is declared by `HasPredefinedCronExpression`.** Implementing the interface is optional — jobs without it require the cron to be set in the UI. The Filament resource checks `$instance instanceof HasPredefinedCronExpression` (not `method_exists`).

**Always use enum cases in queries** — `status` and `triggered_by` are Eloquent-cast enums:
```php
// correct
ScheduledJobRun::where('status', RunStatus::Failed)->count();
// wrong — do not do this
ScheduledJobRun::where('status', 'failed')->count();
```

**Always resolve model classes via config**, never hardcode them:
```php
// correct
config('taskbridge.models.scheduled_job', ScheduledJob::class)
```

**Never merge the two logging paths.** Queue-worker-triggered runs go through `TaskBridgeServiceProvider::registerQueueListeners()`. Manual/dry runs go through `TaskBridge::run()`.

**JobOutputRegistry — never read `$bag` directly.** Always use `retrieveSuccess()` or `retrieveError()`. Both methods clear the entry automatically.

**EventBridgeDriver::sync() is always upsert.** Never add diffing logic using `ScheduleExpression` — `listSchedules` does not return that field.

**Never add `SqsParameters` to the EventBridge payload.** `MessageGroupId` is FIFO-only; standard SQS queues reject it.

**The driver method is `getEventBridge()`, not `getDriver()`.** The old `getDriver()` was removed.

**Errors are in `output['message']`, not an `error` column.** That column was removed.

**`disable()` does not call `sync()`.** It calls `$this->eventBridge->remove()` directly.

**`ScheduleExpressionTimezone` applies only to `cron()` and `rate()` expressions.** The `at()` expression (used by `scheduleOnce()`) is always UTC and must not include `ScheduleExpressionTimezone`. The `EventBridgeDriver` conditionally adds the timezone key only when the expression does not start with `at(`. The datetime must be converted to UTC via `->clone()->utc()` before formatting — do not call `->utc()` on the original Carbon instance, as it mutates in place.

**One-time jobs have a `taskbridge_jobs` row.** `scheduleOnce()` creates a `ScheduledJob` record with `run_once_at` set. These rows are distinct from recurring job rows:
- `$job->isOnce()` — returns `true` when `run_once_at !== null`
- `ScheduledJob::recurring()` scope — `whereNull('run_once_at')` — use this whenever querying recurring jobs (e.g. in `findOrFail`, `enable`, `disable`, middleware lookup)
- Never enable/disable or sync one-time job rows; they are read-only records

**`CronTranslator::describe()` zero-pads hour and minute.** Output must be `"Daily at 08:00"`, never `"Daily at 8:0"`. The implementation uses `str_pad($value, 2, '0', STR_PAD_LEFT)` for both parts.

**`PruneOnceSchedulesJob` exists in this package.** It deletes `taskbridge_jobs` rows where `run_once_at < now()->subDays($retentionDays)`. Constructor: `?int $retentionDays = null` — falls back to `taskbridge.logging.retention_days`. Register it the same way as `PruneRunsJob`.

## Further reading

- @docs/architecture.md — complete class reference, model schemas, enum values, events, migrations
- @config/taskbridge.php — all configuration keys and their defaults
- @tests/ — Pest test suite; fixtures in `tests/Fixtures/`
