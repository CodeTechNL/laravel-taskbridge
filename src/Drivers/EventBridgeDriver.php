<?php

namespace CodeTechNL\TaskBridge\Drivers;

use Aws\Exception\AwsException;
use Aws\Scheduler\SchedulerClient;
use Carbon\Carbon;
use CodeTechNL\TaskBridge\Support\CronTranslator;
use CodeTechNL\TaskBridge\Support\JobInspector;
use CodeTechNL\TaskBridge\Support\ScheduledJobCollection;
use CodeTechNL\TaskBridge\Support\SyncResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * EventBridge Scheduler driver.
 *
 * Uses the AWS EventBridge Scheduler API (SchedulerClient), which supports
 * schedule groups and per-target retry policies. This is distinct from the
 * older EventBridge Rules API (EventBridgeClient).
 *
 * Required IAM permissions:
 *   - scheduler:CreateSchedule, UpdateSchedule, DeleteSchedule, ListSchedules, GetSchedule
 *   - The role_arn must have sqs:SendMessage on the target SQS queue(s).
 */
class EventBridgeDriver
{
    private string $region;

    private string $scheduleGroup;

    private ?string $roleArn;

    private string $timezone;

    private int $maxEventAgeSeconds;

    private int $maxRetryAttempts;

    /** @var SchedulerClient|null */
    private mixed $client = null;

    public function __construct(array $config)
    {
        $this->region = $config['region'] ?? 'eu-west-1';
        $this->scheduleGroup = $config['schedule_group'] ?? 'default';
        $this->roleArn = $config['role_arn'] ?? null;
        $this->timezone = config('app.timezone', 'UTC');
        $this->maxEventAgeSeconds = (int) ($config['retry_policy']['maximum_event_age_seconds'] ?? 86400);
        $this->maxRetryAttempts = (int) ($config['retry_policy']['maximum_retry_attempts'] ?? 185);
    }

    // ── SchedulerDriver contract ───────────────────────────────────────────────

    public function sync(ScheduledJobCollection $enabled): SyncResult
    {
        $created = 0;
        $updated = 0;
        $removed = 0;

        $client = $this->getClient();
        $existing = $this->fetchExistingSchedules($client);
        $enabledIdentifiers = $enabled->identifiers();

        // Remove stale schedules
        foreach (array_keys($existing) as $scheduleName) {
            $identifier = $this->identifierFromScheduleName($scheduleName);

            if (! in_array($identifier, $enabledIdentifiers)) {
                $this->deleteSchedule($client, $scheduleName);
                $removed++;
            }
        }

        // Create or update schedules for enabled jobs
        foreach ($enabled as $job) {
            $scheduleName = $this->scheduleName($job->identifier);
            $cronExpression = CronTranslator::toEventBridge($job->effective_cron);

            if (! isset($existing[$scheduleName])) {
                $this->createSchedule($client, $job, $scheduleName, $cronExpression);
                $created++;
            } else {
                $this->updateSchedule($client, $job, $scheduleName, $cronExpression);
                $updated++;
            }
        }

        return new SyncResult($created, $updated, $removed);
    }

    public function remove(string $identifier): void
    {
        $this->deleteSchedule($this->getClient(), $identifier);
    }

    public function list(): array
    {
        return $this->fetchExistingSchedules($this->getClient());
    }

    /**
     * Create a one-time EventBridge schedule that fires at the given datetime
     * and self-destructs after completion.
     *
     * The schedule name includes a short random suffix so multiple one-time
     * schedules can coexist for the same job without collisions.
     *
     * Returns the schedule name so callers can reference it in log records.
     */
    /**
     * @param  array<int, mixed>  $arguments  Constructor arguments to bake into the SQS job payload.
     */
    public function scheduleOnce(mixed $job, Carbon $at, array $arguments = []): string
    {
        $suffix = strtolower(Str::random(8));
        $scheduleName = "once-{$job->identifier}-{$suffix}";
        $atExpression = 'at('.$at->clone()->utc()->format('Y-m-d\TH:i:s').')';

        $client = $this->getClient();

        try {
            $client->createSchedule($this->buildOnceSchedulePayload($job, $scheduleName, $atExpression, $arguments));
        } catch (\Throwable $e) {
            Log::error('TaskBridge EventBridge: failed to create one-time schedule', [
                'schedule' => $scheduleName,
                'at' => $at->toIso8601String(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $scheduleName;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function fetchExistingSchedules(mixed $client): array
    {
        try {
            $result = $client->listSchedules([
                'GroupName' => $this->scheduleGroup,
                'MaxResults' => 100,
            ]);

            $schedules = [];
            foreach ($result['Schedules'] ?? [] as $schedule) {
                $schedules[$schedule['Name']] = $schedule;
            }

            return $schedules;
        } catch (\Throwable $e) {
            Log::warning('TaskBridge EventBridge: failed to list schedules', [
                'group' => $this->scheduleGroup,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function createSchedule(mixed $client, mixed $job, string $scheduleName, string $cronExpression): void
    {
        try {
            $client->createSchedule($this->buildSchedulePayload($job, $scheduleName, $cronExpression, $job->constructor_arguments ?? []));
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ConflictException') {
                // Schedule already exists (e.g. listing was paginated and missed it) — update instead.
                $this->updateSchedule($client, $job, $scheduleName, $cronExpression);

                return;
            }

            Log::error('TaskBridge EventBridge: failed to create schedule', [
                'schedule' => $scheduleName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            Log::error('TaskBridge EventBridge: failed to create schedule', [
                'schedule' => $scheduleName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function updateSchedule(mixed $client, mixed $job, string $scheduleName, string $cronExpression): void
    {
        try {
            $client->updateSchedule($this->buildSchedulePayload($job, $scheduleName, $cronExpression, $job->constructor_arguments ?? []));
        } catch (\Throwable $e) {
            Log::error('TaskBridge EventBridge: failed to update schedule', [
                'schedule' => $scheduleName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function deleteSchedule(mixed $client, string $scheduleName): void
    {
        try {
            $client->deleteSchedule([
                'Name' => $scheduleName,
                'GroupName' => $this->scheduleGroup,
            ]);
        } catch (\Throwable $e) {
            Log::warning('TaskBridge EventBridge: failed to delete schedule', [
                'schedule' => $scheduleName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function buildOnceSchedulePayload(mixed $job, string $scheduleName, string $atExpression, array $arguments = []): array
    {
        $payload = $this->buildSchedulePayload($job, $scheduleName, $atExpression, $arguments);
        $payload['ActionAfterCompletion'] = 'DELETE';

        return $payload;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function buildSchedulePayload(mixed $job, string $scheduleName, string $cronExpression, array $arguments = []): array
    {
        $maxAge = $job->retry_maximum_event_age_seconds ?? $this->maxEventAgeSeconds;
        $maxRetries = $job->retry_maximum_retry_attempts ?? $this->maxRetryAttempts;
        $queueUrl = $this->resolveQueueUrl($job->queue_connection);

        return [
            'Name' => $scheduleName,
            'GroupName' => $this->scheduleGroup,
            'ScheduleExpression' => $cronExpression,
            // at() expressions are always UTC — only cron/rate need a timezone.
            ...(! str_starts_with($cronExpression, 'at(') ? ['ScheduleExpressionTimezone' => $this->timezone] : []),
            'FlexibleTimeWindow' => ['Mode' => 'OFF'],
            'State' => 'ENABLED',
            'Description' => "TaskBridge: {$job->class}",
            'Target' => [
                'Arn' => $this->queueArnFromUrl($queueUrl),
                'RoleArn' => $this->roleArn ?? throw new \RuntimeException(
                    'TaskBridge: role_arn is required by AWS EventBridge Scheduler. '
                    .'Set TASKBRIDGE_SCHEDULER_ROLE_ARN to the ARN of the IAM role that EventBridge should assume to publish to SQS. '
                    .'When using CDK, pass the role ARN from your CDK stack output.'
                ),
                'Input' => json_encode($this->buildJobPayload($job->class, $arguments)),
                'RetryPolicy' => [
                    'MaximumEventAgeInSeconds' => $maxAge,
                    'MaximumRetryAttempts' => $maxRetries,
                ],
            ],
        ];
    }

    /**
     * Build a Laravel-compatible SQS job payload for the given class.
     *
     * createPayload() is protected on the Queue base class, so we build
     * the payload manually — matching exactly what Laravel's SqsQueue produces.
     *
     * When $arguments are provided the job is instantiated with them so the
     * constructor state is baked into the serialized command. EventBridge will
     * deliver this payload to SQS; the Laravel queue worker deserializes the
     * command and calls handle() — meaning the arguments survive the round-trip.
     *
     * @param  array<int, mixed>  $arguments
     */
    private function buildJobPayload(string $class, array $arguments = []): array
    {
        $job = empty($arguments)
            ? JobInspector::make($class)
            : new $class(...$arguments);

        return [
            'uuid' => (string) Str::uuid(),
            'displayName' => $class,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => $job->tries ?? null,
            'maxExceptions' => $job->maxExceptions ?? null,
            'failOnTimeout' => $job->failOnTimeout ?? false,
            'backoff' => $job->backoff ?? null,
            'timeout' => $job->timeout ?? null,
            'retryUntil' => null,
            'data' => [
                'commandName' => $class,
                'command' => serialize(clone $job),
            ],
        ];
    }

    /**
     * Resolve the full SQS queue URL for a given Laravel queue connection name.
     *
     * Laravel's SQS config stores:
     *   prefix => https://sqs.{region}.amazonaws.com/{account-id}
     *   queue  => {queue-name}
     *
     * The full URL is prefix/queue.
     */
    private function resolveQueueUrl(?string $connection): string
    {
        $connection ??= config('queue.default');
        $cfg = config("queue.connections.{$connection}", []);
        $prefix = rtrim($cfg['prefix'] ?? '', '/');
        $queue = $cfg['queue'] ?? '';

        if (empty($prefix) || empty($queue)) {
            throw new \RuntimeException(
                "TaskBridge: could not resolve SQS queue URL for connection \"{$connection}\". "
                .'Ensure prefix and queue are set in config/queue.php.'
            );
        }

        return "{$prefix}/{$queue}";
    }

    private function scheduleName(string $identifier): string
    {
        return $identifier;
    }

    private function identifierFromScheduleName(string $scheduleName): string
    {
        return $scheduleName;
    }

    /**
     * Derive a SQS ARN from a queue URL.
     * URL format: https://sqs.{region}.amazonaws.com/{account-id}/{queue-name}
     */
    private function queueArnFromUrl(string $queueUrl): string
    {
        $parts = parse_url($queueUrl);
        $pathParts = explode('/', ltrim($parts['path'] ?? '', '/'));
        $accountId = $pathParts[0] ?? '';
        $queueName = $pathParts[1] ?? '';

        return "arn:aws:sqs:{$this->region}:{$accountId}:{$queueName}";
    }

    private function getClient(): mixed
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (! class_exists('\Aws\Scheduler\SchedulerClient')) {
            throw new \RuntimeException(
                'AWS SDK for PHP is required for the EventBridge driver. Run: composer require aws/aws-sdk-php'
            );
        }

        $this->client = new SchedulerClient([
            'region' => $this->region,
            'version' => 'latest',
        ]);

        return $this->client;
    }

    /**
     * Override the client (useful for testing).
     */
    public function setClient(mixed $client): void
    {
        $this->client = $client;
    }
}
