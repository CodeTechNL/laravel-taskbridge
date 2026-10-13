<?php

use CodeTechNL\TaskBridge\Drivers\EventBridgeDriver;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Support\ScheduledJobCollection;
use CodeTechNL\TaskBridge\Testing\FakeSchedulerClient;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleScheduledJob;

function eventBridgeDriverWith(FakeSchedulerClient $client): EventBridgeDriver
{
    $driver = new EventBridgeDriver([
        'region' => 'eu-west-1',
        'schedule_group' => 'taskbridge',
        'role_arn' => 'arn:aws:iam::123456789012:role/taskbridge-scheduler',
    ]);
    $driver->setClient($client);

    return $driver;
}

function recurringJobNamed(string $identifier): ScheduledJob
{
    return new ScheduledJob([
        'class' => ExampleScheduledJob::class,
        'identifier' => $identifier,
        'queue_connection' => 'sqs',
        'cron_expression' => '0 * * * *',
        'enabled' => true,
    ]);
}

describe('EventBridgeDriver', function () {
    beforeEach(function () {
        config()->set('queue.default', 'sqs');
        config()->set('queue.connections.sqs', [
            'driver' => 'sqs',
            'prefix' => 'https://sqs.eu-west-1.amazonaws.com/123456789012',
            'queue' => 'default',
        ]);
    });

    describe('sync', function () {
        it('upserts enabled jobs and deletes the recurring schedules that are no longer enabled', function () {
            $client = new FakeSchedulerClient(['keep-me', 'stale-job']);
            $driver = eventBridgeDriverWith($client);

            $result = $driver->sync(new ScheduledJobCollection([recurringJobNamed('keep-me'), recurringJobNamed('new-job')]));

            expect($client->deletedNames())->toBe(['stale-job']);
            expect($client->updatedNames())->toBe(['keep-me']);
            expect($client->createdNames())->toBe(['new-job']);
            expect($result->created)->toBe(1);
            expect($result->updated)->toBe(1);
            expect($result->removed)->toBe(1);
        });

        it('leaves one-time schedules alone: they self-delete after firing', function () {
            $client = new FakeSchedulerClient([
                'keep-me',
                'once-keep-me-ab12cd34',
                'once-disabled-job-ef56gh78',
                'stale-job',
            ]);
            $driver = eventBridgeDriverWith($client);

            $result = $driver->sync(new ScheduledJobCollection([recurringJobNamed('keep-me')]));

            expect($client->deletedNames())->toBe(['stale-job']);
            expect($client->names())->toBe(['keep-me', 'once-keep-me-ab12cd34', 'once-disabled-job-ef56gh78']);
            expect($result->removed)->toBe(1);
        });

        it('never touches one-time schedules, even when no recurring job is enabled', function () {
            $client = new FakeSchedulerClient(['once-wake-run-00000001', 'once-wake-run-00000002']);
            $driver = eventBridgeDriverWith($client);

            $result = $driver->sync(new ScheduledJobCollection([]));

            expect($client->deleted)->toBe([]);
            expect($client->names())->toHaveCount(2);
            expect($result->removed)->toBe(0);
        });

        it('syncs against every page of the schedule group, not only the first', function () {
            $client = new FakeSchedulerClient(['job-1', 'job-2', 'job-3', 'job-4', 'job-5'], pageSize: 2);
            $driver = eventBridgeDriverWith($client);

            // job-5 only shows up on the last page: it must be updated, not re-created.
            $result = $driver->sync(new ScheduledJobCollection([recurringJobNamed('job-5')]));

            expect($client->listCalls)->toHaveCount(3);
            expect($client->updatedNames())->toBe(['job-5']);
            expect($client->createdNames())->toBe([]);
            expect($client->deletedNames())->toBe(['job-1', 'job-2', 'job-3', 'job-4']);
            expect($result->updated)->toBe(1);
            expect($result->removed)->toBe(4);
        });
    });

    describe('list', function () {
        it('follows NextToken until the group has been read completely', function () {
            $names = array_map(fn (int $i): string => sprintf('job-%03d', $i), range(1, 250));
            $client = new FakeSchedulerClient($names, pageSize: 100);
            $driver = eventBridgeDriverWith($client);

            $schedules = $driver->list();

            expect(array_keys($schedules))->toBe($names);
            expect($client->listCalls)->toHaveCount(3);
            expect($client->listCalls[0])->toBe(['GroupName' => 'taskbridge', 'MaxResults' => 100]);
            expect($client->listCalls[1])->toHaveKey('NextToken', '100');
            expect($client->listCalls[2])->toHaveKey('NextToken', '200');
        });

        it('reads a single-page group with one call', function () {
            $client = new FakeSchedulerClient(['only-job']);
            $driver = eventBridgeDriverWith($client);

            expect(array_keys($driver->list()))->toBe(['only-job']);
            expect($client->listCalls)->toHaveCount(1);
            expect($client->listCalls[0])->not->toHaveKey('NextToken');
        });

        it('returns an empty list when EventBridge cannot be reached', function () {
            $driver = eventBridgeDriverWith(new class extends FakeSchedulerClient
            {
                public function listSchedules(array $args): array
                {
                    throw new RuntimeException('AccessDeniedException');
                }
            });

            expect($driver->list())->toBe([]);
        });
    });

    describe('scheduleOnce', function () {
        it('creates a self-deleting at() schedule named with the once- prefix', function () {
            $client = new FakeSchedulerClient;
            $driver = eventBridgeDriverWith($client);
            $at = now()->addHours(2);

            $scheduleName = $driver->scheduleOnce(recurringJobNamed('keep-me'), $at);

            expect($scheduleName)->toMatch('/^once-keep-me-[a-z0-9]{8}$/');
            expect(EventBridgeDriver::isOnceScheduleName($scheduleName))->toBeTrue();
            expect($client->created)->toHaveCount(1);
            expect($client->created[0]['Name'])->toBe($scheduleName);
            expect($client->created[0]['ActionAfterCompletion'])->toBe('DELETE');
            expect($client->created[0]['ScheduleExpression'])->toBe('at('.$at->clone()->utc()->format('Y-m-d\TH:i:s').')');
            expect($client->created[0])->not->toHaveKey('ScheduleExpressionTimezone');
        });

        it('survives a sync of the recurring jobs', function () {
            $client = new FakeSchedulerClient(['keep-me']);
            $driver = eventBridgeDriverWith($client);

            $scheduleName = $driver->scheduleOnce(recurringJobNamed('keep-me'), now()->addHour());
            $driver->sync(new ScheduledJobCollection([recurringJobNamed('keep-me')]));

            expect($client->deleted)->toBe([]);
            expect($client->names())->toBe(['keep-me', $scheduleName]);
        });

        it('names the schedule in the SQS payload, for one-time and recurring schedules alike', function () {
            $client = new FakeSchedulerClient;
            $driver = eventBridgeDriverWith($client);

            $scheduleName = $driver->scheduleOnce(recurringJobNamed('keep-me'), now()->addHour());
            $driver->sync(new ScheduledJobCollection([recurringJobNamed('keep-me')]));

            $oncePayload = json_decode($client->created[0]['Target']['Input'], true);
            $recurringPayload = json_decode($client->created[1]['Target']['Input'], true);

            expect($oncePayload[EventBridgeDriver::PAYLOAD_SCHEDULE_KEY])->toBe($scheduleName);
            expect($oncePayload['data']['commandName'])->toBe(ExampleScheduledJob::class);
            expect($recurringPayload[EventBridgeDriver::PAYLOAD_SCHEDULE_KEY])->toBe('keep-me');
        });

        it('keeps an identifier of up to 50 characters intact in the schedule name', function () {
            $client = new FakeSchedulerClient;
            $driver = eventBridgeDriverWith($client);
            $identifier = str_repeat('a', 50);

            $scheduleName = $driver->scheduleOnce(recurringJobNamed($identifier), now()->addHour());

            expect($scheduleName)->toMatch('/^once-'.$identifier.'-[a-z0-9]{8}$/');
            expect(strlen($scheduleName))->toBe(EventBridgeDriver::MAX_SCHEDULE_NAME_LENGTH);
        });

        it('shortens a longer identifier so the schedule name stays within the 64-character AWS limit', function () {
            $client = new FakeSchedulerClient;
            $driver = eventBridgeDriverWith($client);
            // 64 characters: the longest identifier identifierFromClass() hands out.
            $identifier = 'production-dispatch-trial-expires-in-three-days-event-job-report';
            expect(strlen($identifier))->toBe(64);

            $scheduleName = $driver->scheduleOnce(recurringJobNamed($identifier), now()->addHour());

            // The readable head survives; the tail becomes 8 hex characters of the identifier's MD5.
            $hash = substr(md5($identifier), 0, 8);
            expect($scheduleName)->toMatch('/^once-production-dispatch-trial-expires-in-thre-'.$hash.'-[a-z0-9]{8}$/');
            expect(strlen($scheduleName))->toBe(EventBridgeDriver::MAX_SCHEDULE_NAME_LENGTH);
            expect(EventBridgeDriver::isOnceScheduleName($scheduleName))->toBeTrue();
            expect($client->created[0]['Name'])->toBe($scheduleName);
        });

        it('shortens deterministically and tells identifiers with the same head apart', function () {
            $client = new FakeSchedulerClient;
            $driver = eventBridgeDriverWith($client);
            $at = now()->addHour();
            $identifier = 'production-dispatch-trial-expires-in-three-days-event-job-report';
            $sibling = 'production-dispatch-trial-expires-in-three-days-event-job-digest';

            $first = $driver->scheduleOnce(recurringJobNamed($identifier), $at);
            $second = $driver->scheduleOnce(recurringJobNamed($identifier), $at);
            $third = $driver->scheduleOnce(recurringJobNamed($sibling), $at);

            // Everything but the random suffix is the same for the same identifier ...
            $stem = fn (string $name): string => substr($name, 0, -9);
            expect($stem($first))->toBe($stem($second));
            expect($first)->not->toBe($second);
            // ... and differs for another identifier that shares the readable head.
            expect($stem($third))->not->toBe($stem($first));
            expect($third)->toStartWith('once-production-dispatch-trial-expires-in-thre-');
        });

        it('does not leave a dangling dash where the identifier is cut', function () {
            $client = new FakeSchedulerClient;
            $driver = eventBridgeDriverWith($client);
            $identifier = str_repeat('a', 40).'-'.str_repeat('b', 20);

            $scheduleName = $driver->scheduleOnce(recurringJobNamed($identifier), now()->addHour());

            expect($scheduleName)->toMatch('/^once-a{40}-[0-9a-f]{8}-[a-z0-9]{8}$/');
        });
    });

    describe('isOnceScheduleName', function () {
        it('recognises only names carrying the once- prefix', function () {
            expect(EventBridgeDriver::isOnceScheduleName('once-send-report-ab12cd34'))->toBeTrue();
            expect(EventBridgeDriver::isOnceScheduleName('send-report'))->toBeFalse();
            expect(EventBridgeDriver::isOnceScheduleName('production-once-send-report'))->toBeFalse();
        });
    });
});
