<?php

namespace CodeTechNL\TaskBridge\Drivers;

use Aws\Scheduler\SchedulerClient;
use CodeTechNL\TaskBridge\Support\CronTranslator;
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

    private string $prefix;

    private string $scheduleGroup;

    private string $roleArn;

    private int $maxEventAgeSeconds;

    private int $maxRetryAttempts;

    /** @var SchedulerClient|null */
    private mixed $client = null;

    public function __construct(array $config)
    {
        $this->region = $config['region'] ?? 'eu-west-1';
        $this->prefix = $config['prefix'] ?? 'taskbridge';
        $this->scheduleGroup = $config['schedule_group'] ?? 'default';
        $this->roleArn = $config['role_arn'] ?? '';
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
        $this->deleteSchedule($this->getClient(), $this->scheduleName($identifier));
    }

    public function list(): array
    {
        return $this->fetchExistingSchedules($this->getClient());
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function fetchExistingSchedules(mixed $client): array
    {
        try {
            $result = $client->listSchedules([
                'GroupName' => $this->scheduleGroup,
                'NamePrefix' => $this->prefix.'-',
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
            $client->createSchedule($this->buildSchedulePayload($job, $scheduleName, $cronExpression));
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
            $client->updateSchedule($this->buildSchedulePayload($job, $scheduleName, $cronExpression));
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

    private function buildSchedulePayload(mixed $job, string $scheduleName, string $cronExpression): array
    {
        $maxAge = $job->retry_maximum_event_age_seconds ?? $this->maxEventAgeSeconds;
        $maxRetries = $job->retry_maximum_retry_attempts ?? $this->maxRetryAttempts;
        $queueUrl = $this->resolveQueueUrl($job->queue_connection);

        return [
            'Name' => $scheduleName,
            'GroupName' => $this->scheduleGroup,
            'ScheduleExpression' => $cronExpression,
            'FlexibleTimeWindow' => ['Mode' => 'OFF'],
            'State' => 'ENABLED',
            'Description' => "TaskBridge: {$job->class}",
            'Target' => [
                'Arn' => $this->queueArnFromUrl($queueUrl),
                'RoleArn' => $this->roleArn,
                'Input' => json_encode($this->buildJobPayload($job->class)),
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
     */
    private function buildJobPayload(string $class): array
    {
        $job = new $class;

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
        return "{$this->prefix}-{$identifier}";
    }

    private function identifierFromScheduleName(string $scheduleName): string
    {
        return substr($scheduleName, strlen("{$this->prefix}-"));
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
