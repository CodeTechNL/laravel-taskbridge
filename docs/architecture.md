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
| `ReportsTaskOutput` | *(marker — no methods)* | Signals the job uses `HasJobOutput` to report metadata |

### Auto-detection fallbacks

When optional interfaces are not implemented, TaskBridge derives values automatically:

- **Label** — converts the class basename to sentence case: `SendDailyReport` → `"Send daily report"`
- **Group** — uses the namespace segment directly above the class name: `App\Jobs\Reporting\SendDailyReport` → `"Reporting"`. Returns `null` when the class lives directly in a root segment (`Jobs`, `Commands`, etc.)
- **Cron** — `cronExpression()` is not part of any interface. Add the method to the job class to provide a default; omit it to require the cron to be set in the UI. Checked via `method_exists()`.

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
| `retry_maximum_event_age_seconds` | int? | 60–86400; null = config default |
| `retry_maximum_retry_attempts` | int? | 0–185; null = config default |
| `enabled` | bool | `false` removes the schedule from EventBridge |
| `last_run_at` | datetime? | Updated after every run |
| `last_status` | RunStatus? | Cast to enum |

- `effective_cron` computed attribute returns `cron_override ?? cron_expression` — may be `null` if neither is set
- `runs()` hasMany → `ScheduledJobRun`
- `identifierFromClass(string $class)` static — applies `taskbridge.name_prefix` then kebab-cases the basename
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
| `describe(string): string` | Human-readable sentence |
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

- `sync(ScheduledJobCollection): SyncResult` — calls `listSchedules` to find existing schedules; calls `updateSchedule` for matches, `createSchedule` for new ones; removes orphaned schedules. Always upserts — never diffs by `ScheduleExpression` (not returned by `listSchedules`).
- `remove(string $identifier)` — deletes the prefixed schedule
- `buildSchedulePayload(ScheduledJob): array` — full request body: SQS target, retry policy, IAM role, JSON-encoded Laravel job payload. Never include `SqsParameters` — only valid for FIFO queues.
- `resolveQueueUrl(?string $connection)` — reads `queue.connections.{connection}.queue` from Laravel config
- Schedule names: `{prefix}-{identifier}` (e.g. `taskbridge-send-invoice`)

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
| `run(string, bool $dryRun, bool $force): ScheduledJobRun` | |
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
1. Extract `commandName` from SQS payload
2. Check if the class is registered via `TaskBridge::isRegistered()` and exists in DB
3. Create `ScheduledJobRun` (status=Running, triggered_by=Scheduler)
4. Register a `JobQueued` listener for sub-dispatch counting
5. Store tracking data keyed by job UUID

`JobProcessed` event:
1. Look up tracking by UUID
2. Deregister `JobQueued` listener
3. Update run: Succeeded + duration + jobs_dispatched + output from registry
4. Update job: last_run_at + last_status

`JobFailed` event:
1. Look up tracking by UUID
2. Deregister `JobQueued` listener
3. Update run: Failed + output with error message
4. Update job: last_run_at + last_status

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

---

## Testing

`TaskBridgeFake` (`src/Testing/`) creates a real `EventBridgeDriver` with a null `SchedulerClient` — no AWS calls are made.

Test fixtures (`tests/Fixtures/`):

| Fixture | Description |
|---------|-------------|
| `ExampleScheduledJob` | Basic `ShouldQueue` job, cron `0 * * * *` |
| `ExampleConditionalJob` | Implements `RunsConditionally`; `shouldRun` configurable via constructor |
| `ExampleOutputJob` | Implements `ReportsTaskOutput` + `HasJobOutput`; reports `['processed' => 42, 'skipped' => 3]` |

All tests use SQLite in-memory (Orchestra Testbench). Migrations are auto-loaded in `TestCase::setUp()`.
