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

**`ScheduledJob` interface does not exist.** Any `ShouldQueue` job can be used with TaskBridge. Discovery uses `ShouldQueue`, the middleware checks `TaskBridge::isRegistered()`. Never re-introduce a `ScheduledJob` interface.

**Interface names are final.** The four optional interfaces are: `RunsConditionally`, `HasGroup`, `HasCustomLabel`, `ReportsTaskOutput`. Do not use or reference the old names (`ConditionalJob`, `GroupedJob`, `LabeledJob`, `ReportsOutput`).

**`ReportsTaskOutput` requires `reportOutput()`.** The interface declares `reportOutput(array $metadata): void` — it is no longer a marker. The `HasJobOutput` trait satisfies it:
```php
class ImportProducts implements ReportsTaskOutput, ShouldQueue
{
    use HasJobOutput; // provides the required reportOutput() implementation
}
```

**`cronExpression()` is not part of any interface.** It is an optional method checked via `method_exists()`. Jobs without it require the cron to be set in the UI. Never add it to an interface.

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

## Further reading

- @docs/architecture.md — complete class reference, model schemas, enum values, events, migrations
- @config/taskbridge.php — all configuration keys and their defaults
- @tests/ — Pest test suite; fixtures in `tests/Fixtures/`
