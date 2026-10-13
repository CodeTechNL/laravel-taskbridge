# laravel-taskbridge — Architecture Reference

Complete class reference for developers and AI agents modifying this package. For usage and setup, see @README.md. For universal rules, see @AGENTS.md. For all config keys, see @config/taskbridge.php.

---

## Contracts

The `ScheduledJob` interface no longer exists. Any `ShouldQueue` job can be used with TaskBridge — no extra interface is required. The following interfaces are all optional and add specific behaviour.

| Interface | Method | Purpose |
|-----------|--------|---------|
| `RunsConditionally` | `shouldRun(): bool` | Return `false` to skip execution at runtime — logged as `Skipped` |
| `HasGroup` | `group(): string` | Overrides the auto-detected group in the UI |
| `HasCustomLabel` | `taskLabel(): string` | Overrides the auto-derived readable label in the UI |
| `ReportsTaskOutput` | `reportOutput(array $metadata): void` | Requires structured output reporting; satisfied by the `HasJobOutput` trait |

### Auto-detection fallbacks

When optional interfaces are not implemented, TaskBridge derives values automatically:

- **Label** — converts the class basename to sentence case: `SendDailyReport` → `"Send daily report"`
- **Group** — uses the namespace segment directly above the class name: `App\Jobs\Reporting\SendDailyReport` → `"Reporting"`. Returns `null` when the class lives directly in a root segment (`Jobs`, `Commands`, etc.)
- **Cron** — `cronExpression()` is not part of any interface. Add the method to the job class to provide a default; omit it to require the cron to be set in the UI. Checked via `method_exists()`.

---

## Commands

### `ImportSchedulesCommand` (artisan: `taskbridge:import-schedules`)

Reads `config('taskbridge.schedules')`, validates each entry, and upserts it into the database.

- Invalid entries are skipped with a warning; processing continues with the remaining entries
- Returns `Command::FAILURE` if any entry failed; `Command::SUCCESS` if all entries were imported

**Public static helpers** (reused by Filament actions):

| Method | Signature | Description |
|--------|-----------|-------------|
| `parseEntry` | `parseEntry(array $value): array` | Normalises a single config entry; validates structure, class existence, scalar constructor, valid cron, and correct argument count. Returns an array suitable for upsert. |
| `validateArguments` | `validateArguments(string $class, array $arguments): ?string` | Checks that the argument count matches the constructor's required parameters. Returns an error string on failure, or `null` on success. |

These methods must be called from Filament (e.g. `ImportSchedulesAction`) rather than duplicating the logic.

---

## Models

### `ScheduledJob` (`taskbridge_jobs`)

| Column | Type | Notes |
|--------|------|-------|
| `id` | ulid | Primary key |
| `class` | string | FQCN |
| `identifier` | string | Kebab-case from class basename + optional name_prefix |
| `group` | string? | Grouping label — auto-populated from class on create |
| `description` | text? | Optional notes / description set in the UI |
| `queue_connection` | string? | Laravel queue connection (SQS only) |
| `cron_expression` | string? | Default cron from `cronExpression()` — nullable |
| `cron_override` | string? | UI/API override; takes precedence over `cron_expression` |
| `constructor_arguments` | json? | Positional array of scalar values baked into the SQS payload |
| `retry_maximum_event_age_seconds` | int? | 60–86400; null = config default |
| `retry_maximum_retry_attempts` | int? | 0–185; null = config default |
| `enabled` | bool | `false` removes the schedule from EventBridge |
| `last_run_at` | datetime? | Updated after every run |
| `last_status` | RunStatus? | Cast to enum |
| `run_once_at` | datetime? | Set for one-time schedules; null for recurring |
| `run_once_schedule_name` | string? | The EventBridge schedule name for a one-time job; unique |

- `effective_cron` computed attribute returns `cron_override ?? cron_expression` — may be `null` if neither is set
- `isOnce(): bool` — returns `true` when `run_once_at !== null`
- `scopeRecurring($query)` — `whereNull('run_once_at')` — use this when querying jobs that should be synced/enabled/disabled
- `runs()` hasMany → `ScheduledJobRun`
- `identifierFromClass(string $class)` static — applies `taskbridge.name_prefix` then kebab-cases the basename. **64-char limit enforced:** if the result exceeds 64 characters, the bare class-name segment is replaced with `md5($bareClassName)` (prefix excluded from hash). If `prefix + "-" + md5` would still exceed 64 chars, a `RuntimeException` is thrown.
- Returns `ScheduledJobCollection` from `newCollection()`

### `ScheduledJobRun` (`taskbridge_job_runs`)

| Column | Type | Notes |
|--------|------|-------|
| `id` | ulid | Primary key |
| `scheduled_job_id` | ulid | FK → `taskbridge_jobs` |
| `status` | RunStatus | Cast to enum |
| `triggered_by` | TriggeredBy | Cast to enum |
| `started_at` | datetime | Set when run is created |
| `finished_at` | datetime? | Set on completion |
| `duration_ms` | int? | Wall-clock duration |
| `jobs_dispatched` | int | Count of sub-jobs queued during run |
| `output` | json? | Serialised `JobOutput` (status / message / metadata) |
| `skipped_reason` | string? | Populated when status = Skipped |

---

## Enums

### `RunStatus` (backed: string)

| Case | Value | Color |
|------|-------|-------|
| `Pending` | `pending` | `gray` |
| `Running` | `running` | `primary` |
| `Succeeded` | `succeeded` | `success` |
| `Failed` | `failed` | `danger` |
| `Skipped` | `skipped` | `warning` |

`label()` → translatable via `taskbridge::enums.run_status.*`

### `TriggeredBy` (backed: string)

| Case | Value | Color |
|------|-------|-------|
| `Scheduler` | `scheduler` | `gray` |
| `Manual` | `manual` | `primary` |
| `DryRun` | `dry_run` | `warning` |
| `ScheduledOnce` | `scheduled_once` | `info` |

`label()` → translatable via `taskbridge::enums.triggered_by.*`

---

## Data: `JobOutput`

Final class with readonly properties: `status string`, `message string`, `metadata array`.

| Factory | Stored status |
|---------|--------------|
| `::success(message, metadata)` | `success` |
| `::error(message)` | `error` |
| `::warning(message, metadata)` | `warning` |
| `::info(message, metadata)` | `info` |

- `toArray()` omits empty `message` and `metadata` to keep stored JSON lean
- `fromArray()` reconstructs from stored JSON; defaults `status` to `'info'` if missing
- `color()` maps status to a Filament colour string; unknown → `'gray'`
- `label()` → `ucfirst($status)`

---

## Support classes

### `JobOutputRegistry` (static)

Bridges a running job and the queue event listener, which has no access to the job instance.

```
Job::handle()
    └─▶ $this->reportOutput(['rows' => 42])
            └─▶ JobOutputRegistry::store(static::class, ['rows' => 42])

JobProcessed listener
    └─▶ ::retrieveSuccess('App\Jobs\MyJob')
            └─▶ JobOutput::success('Success', ['rows' => 42])  +  clears entry

JobFailed listener
    └─▶ ::retrieveError('App\Jobs\MyJob', 'Exception message')
            └─▶ JobOutput::error('Exception message')  +  clears entry
```

### `CronTranslator`

Converts between 5-part standard cron and 6-part AWS EventBridge cron.

```
5-part:  minute hour dom month dow           (dow 0 = Sunday)
6-part:  minute hour dom month dow year      (dow 1 = Sunday; exactly one of dom/dow must be ?)
```

| Method | Returns |
|--------|---------|
| `toEventBridge(string): string` | Wrapped in `cron(…)`; throws on invalid input |
| `isValid(string): bool` | Validates both 5-part and 6-part |
| `describe(string): string` | Human-readable sentence — hour and minute are always zero-padded (e.g. `"Daily at 08:00"`, never `"Daily at 8:0"`) |
| `nextRunAt(string): DateTimeImmutable` | |
| `previousRunAt(string): DateTimeImmutable` | |

### `JobDiscoverer`

Scans filesystem paths using `Symfony\Component\Finder\Finder`. Extracts namespace and class name via regex, then uses `ReflectionClass` to confirm the class is non-abstract and implements `ShouldQueue`. Non-PHP files, unloadable classes, and abstract classes are silently skipped.

### `ScheduledJobCollection` (extends `Eloquent\Collection`)

| Method | Description |
|--------|-------------|
| `enabled()` | Filter to enabled jobs |
| `disabled()` | Filter to disabled jobs |
| `byGroup(string)` | Filter by group attribute |
| `identifiers()` | Pluck `identifier` values as array |

### `SyncResult` (readonly DTO)

Fields: `created int`, `updated int`, `removed int`, `unchanged int`. Immutable builder: `withCreated(n)` etc. each returns a new instance. `merge(SyncResult)` sums all fields.

---

## `EventBridgeDriver`

Wraps the AWS SDK `SchedulerClient`. All EventBridge communication happens here.

- `sync(ScheduledJobCollection): SyncResult` — calls `listSchedules` (full schedule group, no name prefix filter, paged through `NextToken`) to find existing schedules; calls `updateSchedule` for matches, `createSchedule` for new ones; removes orphaned recurring schedules. One-time schedules (`once-…`) are skipped by the removal loop — they self-delete after firing. Always upserts — never diffs by `ScheduleExpression` (not returned by `listSchedules`).
- `remove(string $identifier)` — deletes the schedule whose name equals the stored identifier directly. No prefix wrapping or double-prefix detection needed.
- `buildSchedulePayload(ScheduledJob): array` — full request body: SQS target, retry policy, IAM role, JSON-encoded Laravel job payload. The job payload names its schedule under `taskbridge_schedule` (`PAYLOAD_SCHEDULE_KEY`); Laravel ignores the key, the queue listener correlates by it. Never include `SqsParameters` — only valid for FIFO queues.
- `scheduleOnce(ScheduledJob $job, Carbon $at, array $arguments = []): string` — creates a one-time schedule using an `at()` expression in UTC and returns its name, `once-{identifier}-{suffix}` (`ONCE_PREFIX` + identifier + 8 random characters). The EventBridge schedule has `ActionAfterCompletion: DELETE`. The name is built by `onceScheduleName()` and never exceeds `MAX_SCHEDULE_NAME_LENGTH` (64): an identifier longer than 50 characters is cut to its first 41 characters (a trailing dash trimmed) plus 8 hexadecimal characters of its MD5 — deterministic, so the same identifier always yields the same stem.
- `isOnceScheduleName(string): bool` (static) — whether a schedule name carries `ONCE_PREFIX`; the guard `sync()` uses.
- `list(): array` — every schedule in the group keyed by name, paged through `NextToken`. A failed listing yields `[]` (logged as a warning), so a sync then removes nothing and relies on create-then-update.
- `resolveQueueUrl(?string $connection)` — reads `queue.connections.{connection}.queue` from Laravel config
- Schedule names equal the identifier directly (e.g. `production-send-invoice`). No additional driver-level prefix is applied.

**Timezone handling:**
- `cron()` and `rate()` expressions include `ScheduleExpressionTimezone` set to `config('app.timezone')`.
- `at()` expressions (one-time) are **always UTC** and must not include `ScheduleExpressionTimezone`. The driver checks `str_starts_with($expression, 'at(')` before adding the timezone key.
- The Carbon `$at` datetime must be cloned before calling `->utc()` to avoid mutating the original: `$at->clone()->utc()->format('Y-m-d\TH:i:s')`.

---

## `TaskBridge` service

Singleton bound as `taskbridge`. Accessed via the `TaskBridge` facade.

| Method | Description |
|--------|-------------|
| `register(array $classes)` | Add classes to in-memory registry |
| `getRegisteredClasses(): array` | Return registered classes |
| `isRegistered(string $class): bool` | Check if a class is in the registry |
| `enable(string $class)` | Set `enabled=true` + sync to EventBridge |
| `disable(string $class)` | Set `enabled=false` + remove from EventBridge |
| `overrideCron(string, string)` | Set `cron_override` + sync |
| `resetCron(string $class)` | Clear `cron_override` + sync |
| `sync(): SyncResult` | Sync all enabled jobs to EventBridge |
| `getEventBridge(): EventBridgeDriver` | |
| `run(string, bool $dryRun, bool $force, array $arguments): ScheduledJobRun` | |
| `scheduleOnce(string, Carbon $at, array $arguments = [], bool $log = true): ScheduledJobRun` | One-time schedule; `log: false` writes no rows, the returned run is transient and carries the name in `output['aws_schedule_name']` |
| `all(): ScheduledJobCollection` | All jobs from DB |
| `enabled(): ScheduledJobCollection` | Enabled jobs from DB |

`run()` detail:
- Instantiates the job class
- Unless `$force=true`, checks `enabled` and `shouldRun()` (if `RunsConditionally`) — skips if either is false
- Dry run: calls `Bus::fake()` before `handle()`, blocks actual dispatches
- Normal run: hooks `JobQueued` listener to count sub-dispatches, calls `handle()` directly
- Captures output via `JobOutputRegistry`
- Creates `ScheduledJobRun` record only when `taskbridge.logging.enabled = true`

---

## Execution paths

There are two separate paths — do not merge them.

### Path A — Queue worker (scheduler-triggered)

Triggered when EventBridge puts a message on SQS and a queue worker picks it up.

`JobProcessing` event:
1. Extract `commandName` and `taskbridge_schedule` (the name of the schedule that fired) from the SQS payload
2. Check if the class is registered via `TaskBridge::isRegistered()`
3. Resolve the job row (`resolveScheduledJob()`): a `once-` name maps to the row with that `run_once_schedule_name` and to nothing else (no row = the schedule was created with `log: false` = not logged); another name maps to the recurring row with that identifier; a payload without the key (a schedule created before the key existed, a job the application dispatched itself) falls back to the class, recurring row first
4. Start the run (`startRun()`): a one-time row continues the pending run `scheduleOnce()` wrote (status=Running, started_at set); otherwise a new run is created (status=Running, triggered_by=Scheduler, or ScheduledOnce on a one-time row whose pending run is gone)
5. Register a `JobQueued` listener for sub-dispatch counting
6. Store tracking data keyed by job UUID

`JobProcessed` event:
1. Look up tracking by UUID
2. Deregister `JobQueued` listener
3. Update run: Succeeded + duration + jobs_dispatched + output from registry, merged over the output the run already held (a pending run keeps its `job_class` and `aws_schedule_name`)
4. Update job: last_run_at + last_status (on the one-time row for a one-time schedule)

`JobFailed` event:
1. Look up tracking by UUID
2. Deregister `JobQueued` listener
3. Update run: Failed + output with error message, merged over the output the run already held
4. Update job: last_run_at + last_status (on the one-time row for a one-time schedule)

### Path B — Direct execution (`TaskBridge::run()`)

Used by "Run now" and "Dry run" UI actions. Runs synchronously in the HTTP request cycle. Creates `ScheduledJobRun` directly; does not go through queue listeners.

---

## Migrations

| File | Description |
|------|-------------|
| `000001` | Create `taskbridge_jobs` table |
| `000002` | Create `taskbridge_job_runs` table |
| `000003` | Add `triggered_by` to job runs |
| `000004` | Add driver columns (later dropped) |
| `000005` | Drop driver columns |
| `000006` | Create settings table (later dropped) |
| `000007` | Add retry policy columns to jobs |
| `000008` | Add `queue_connection` to jobs |
| `000009` | Add `output` (json) to job runs |
| `000010` | Add `description` (text, nullable) to jobs |
| `000011` | Make `cron_expression` nullable on jobs |
| `000012–000014` | Reserved / internal |
| `000015` | Add `constructor_arguments` (json, nullable) to jobs |
| `000016` | Add `run_once_at` (timestamp, nullable) and `run_once_schedule_name` (string, nullable, unique) to jobs. **SQLite `down()` pattern:** the `down()` method uses two separate `Schema::table` calls — first to drop the unique index, then to drop the columns. SQLite cannot drop a column with an attached unique index in a single call. |

---

## Testing

`TaskBridgeFake::fake()` (`src/Testing/`) swaps the container's `TaskBridge` for a fake whose real `EventBridgeDriver` talks to a `FakeSchedulerClient` — no AWS calls are made. `FakeSchedulerClient` keeps the schedules of one group in memory, records every create/update/delete (`created`, `updated`, `deleted`, plus `createdNames()` etc.), and pages `listSchedules()` through `NextToken` with a configurable page size; `tests/Unit/EventBridgeDriverTest.php` hands one to a driver directly via `setClient()`. The fake is reachable through `TaskBridgeFake::schedulerClient()`.

Test fixtures (`tests/Fixtures/`):

| Fixture | Description |
|---------|-------------|
| `ExampleScheduledJob` | Basic `ShouldQueue` job, cron `0 * * * *` |
| `ExampleConditionalJob` | Implements `RunsConditionally`; `shouldRun` configurable via constructor |
| `ExampleOutputJob` | Implements `ReportsTaskOutput` + `HasJobOutput`; reports `['processed' => 42, 'skipped' => 3]` |

**`CommandTestCase`** (`tests/Commands/`): A specialised test case base class for command tests. It sets up the database schema manually (via `Schema::create` calls) and tears it down after each test, bypassing the normal migration chain. This is necessary because several migrations in the chain fail on SQLite when run in reverse (see the known issue in the main AGENTS.md). Tests for `ImportSchedulesCommand` live in `tests/Commands/`.

## Built-in jobs

| Class | Constructor | Purpose |
|-------|------------|---------|
| `PruneRunsJob` | `?int $retentionDays = null` | Deletes `taskbridge_job_runs` rows older than the retention window |
| `PruneOnceSchedulesJob` | `?int $retentionDays = null` | Deletes `taskbridge_jobs` rows where `run_once_at < now()->subDays($n)` |
| `CheckMissedJobs` | none | Dispatches `JobMissed` for jobs overdue by 2× their cron interval |

Both prune jobs fall back to `taskbridge.logging.retention_days` config when `$retentionDays` is `null`.

All tests use SQLite in-memory (Orchestra Testbench). `TestCase::setUp()` runs the package migrations with `Artisan::call('migrate')` and registers no rollback: the database is new for every test, and the chain's `down()` methods fail on SQLite once a test has left rows the older schema cannot hold (a pending run without `started_at`, a one-time job without `cron_expression`).
