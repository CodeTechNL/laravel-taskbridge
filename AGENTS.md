# AGENTS.md — laravel-taskbridge

For full context on this package, read @README.md. For architecture details, read @docs/architecture.md.

## Commands

Run after every code change — both must pass before finishing:

```bash
./vendor/bin/pint   # code style (default ruleset)
./vendor/bin/pest   # all 103 tests must pass
```

If `vendor/` is missing, run `composer install` first.

## Rules

**This package is an addition, not a replacement.** It works alongside Laravel's built-in scheduler. Never describe or document it as a replacement for `Kernel::schedule()`.

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
// wrong
ScheduledJob::where(...)
```

**Never merge the two logging paths.** Queue-worker-triggered runs go through `TaskBridgeServiceProvider::registerQueueListeners()`. Manual/dry runs go through `TaskBridge::run()`. They have different trigger semantics and must stay separate.

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
