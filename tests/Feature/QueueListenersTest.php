<?php

use CodeTechNL\TaskBridge\Drivers\EventBridgeDriver;
use CodeTechNL\TaskBridge\Enums\RunStatus;
use CodeTechNL\TaskBridge\Enums\TriggeredBy;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Models\ScheduledJobRun;
use CodeTechNL\TaskBridge\Support\ScheduledJobCollection;
use CodeTechNL\TaskBridge\Testing\FakeSchedulerClient;
use CodeTechNL\TaskBridge\Testing\TaskBridgeFake;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleScheduledJob;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

/**
 * The queue job a worker would hand the listeners: its payload is the one
 * EventBridge put on SQS for the schedule.
 *
 * @param  array<string, mixed>  $payload
 */
function firedJob(array $payload): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn($payload);

    return $job;
}

/**
 * The SQS payload of the schedule the fake client created under that name.
 *
 * @return array<string, mixed>
 */
function schedulePayload(FakeSchedulerClient $client, string $scheduleName): array
{
    foreach ($client->created as $created) {
        if ($created['Name'] === $scheduleName) {
            return json_decode($created['Target']['Input'], true);
        }
    }

    throw new RuntimeException("No schedule named {$scheduleName} was created.");
}

function recurringExampleRow(): ScheduledJob
{
    return ScheduledJob::create([
        'class' => ExampleScheduledJob::class,
        'identifier' => 'example-scheduled-job',
        'queue_connection' => 'sqs',
        'cron_expression' => '0 * * * *',
        'enabled' => true,
    ]);
}

describe('Queue listeners', function () {
    beforeEach(function () {
        config()->set('taskbridge.name_prefix', null);
        config()->set('queue.default', 'sqs');
        config()->set('queue.connections.sqs', [
            'driver' => 'sqs',
            'prefix' => 'https://sqs.eu-west-1.amazonaws.com/123456789012',
            'queue' => 'default',
        ]);

        $this->taskBridge = TaskBridgeFake::fake();
        $this->taskBridge->register([ExampleScheduledJob::class]);
        $this->client = $this->taskBridge->schedulerClient();
        $this->at = now()->addHour()->startOfMinute();
    });

    describe('one-time schedules', function () {
        it('moves the pending run to Running when the schedule fires', function () {
            $pending = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at);
            $payload = schedulePayload($this->client, $pending->output['aws_schedule_name']);

            Event::dispatch(new JobProcessing('sqs', firedJob($payload)));

            expect(ScheduledJobRun::count())->toBe(1);
            $run = $pending->fresh();
            expect($run->status)->toBe(RunStatus::Running);
            expect($run->triggered_by)->toBe(TriggeredBy::ScheduledOnce);
            expect($run->started_at)->not->toBeNull();
            expect($run->scheduled_for->equalTo($this->at))->toBeTrue();
        });

        it('completes the pending run and stamps the one-time row, not the recurring row of the class', function () {
            $recurring = recurringExampleRow();
            $pending = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at, ['weekly']);
            $scheduleName = $pending->output['aws_schedule_name'];
            $job = firedJob(schedulePayload($this->client, $scheduleName));

            Event::dispatch(new JobProcessing('sqs', $job));
            Event::dispatch(new JobProcessed('sqs', $job));

            expect(ScheduledJobRun::count())->toBe(1);
            $run = $pending->fresh();
            expect($run->status)->toBe(RunStatus::Succeeded);
            expect($run->finished_at)->not->toBeNull();
            expect($run->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0);
            // The bookkeeping the pending run carried survives its completion.
            expect($run->output['aws_schedule_name'])->toBe($scheduleName);
            expect($run->output['job_class'])->toBe(ExampleScheduledJob::class);

            $once = ScheduledJob::where('run_once_schedule_name', $scheduleName)->sole();
            expect($once->last_status)->toBe(RunStatus::Succeeded);
            expect($once->last_run_at)->not->toBeNull();
            expect($recurring->fresh()->last_status)->toBeNull();
            expect($recurring->fresh()->last_run_at)->toBeNull();
        });

        it('marks the pending run Failed when the job fails', function () {
            $pending = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at);
            $scheduleName = $pending->output['aws_schedule_name'];
            $job = firedJob(schedulePayload($this->client, $scheduleName));

            Event::dispatch(new JobProcessing('sqs', $job));
            Event::dispatch(new JobFailed('sqs', $job, new RuntimeException('boom')));

            expect(ScheduledJobRun::count())->toBe(1);
            $run = $pending->fresh();
            expect($run->status)->toBe(RunStatus::Failed);
            expect($run->output['status'])->toBe('error');
            expect($run->output['message'])->toBe('boom');
            expect($run->output['aws_schedule_name'])->toBe($scheduleName);
            expect(ScheduledJob::where('run_once_schedule_name', $scheduleName)->sole()->last_status)->toBe(RunStatus::Failed);
        });

        it('starts a fresh run on the one-time row when its pending run is gone', function () {
            $pending = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at);
            $scheduleName = $pending->output['aws_schedule_name'];
            $pending->delete();
            $job = firedJob(schedulePayload($this->client, $scheduleName));

            Event::dispatch(new JobProcessing('sqs', $job));
            Event::dispatch(new JobProcessed('sqs', $job));

            $once = ScheduledJob::where('run_once_schedule_name', $scheduleName)->sole();
            $run = ScheduledJobRun::sole();
            expect($run->scheduled_job_id)->toBe($once->id);
            expect($run->status)->toBe(RunStatus::Succeeded);
            expect($run->triggered_by)->toBe(TriggeredBy::ScheduledOnce);
            expect($run->scheduled_for->equalTo($this->at))->toBeTrue();
            expect($once->last_status)->toBe(RunStatus::Succeeded);
        });

        it('continues the pending run of a one-time row by class when the payload carries no schedule name', function () {
            // Schedules created before the payload named its schedule still fire with the old payload.
            $pending = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at);
            $payload = schedulePayload($this->client, $pending->output['aws_schedule_name']);
            unset($payload[EventBridgeDriver::PAYLOAD_SCHEDULE_KEY]);

            Event::dispatch(new JobProcessing('sqs', firedJob($payload)));

            expect(ScheduledJobRun::count())->toBe(1);
            expect($pending->fresh()->status)->toBe(RunStatus::Running);
        });

        it('logs nothing for a schedule created with log: false, not even on the recurring row', function () {
            $recurring = recurringExampleRow();
            $transient = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at, log: false);
            $job = firedJob(schedulePayload($this->client, $transient->output['aws_schedule_name']));

            Event::dispatch(new JobProcessing('sqs', $job));
            Event::dispatch(new JobProcessed('sqs', $job));

            expect(ScheduledJobRun::count())->toBe(0);
            expect($recurring->fresh()->last_run_at)->toBeNull();
        });
    });

    describe('recurring schedules', function () {
        it('tracks the run on the recurring row even when a one-time row for the class exists', function () {
            // The one-time row comes first, so a lookup by class alone would land on it.
            $pending = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at);
            $recurring = recurringExampleRow();
            $this->taskBridge->getEventBridge()->sync(new ScheduledJobCollection([$recurring]));
            $job = firedJob(schedulePayload($this->client, 'example-scheduled-job'));

            Event::dispatch(new JobProcessing('sqs', $job));
            Event::dispatch(new JobProcessed('sqs', $job));

            expect(ScheduledJobRun::count())->toBe(2);
            $run = ScheduledJobRun::where('scheduled_job_id', $recurring->id)->sole();
            expect($run->status)->toBe(RunStatus::Succeeded);
            expect($run->triggered_by)->toBe(TriggeredBy::Scheduler);
            expect($run->scheduled_for)->toBeNull();
            expect($run->output)->toBeNull();
            expect($pending->fresh()->status)->toBe(RunStatus::Pending);
            expect($recurring->fresh()->last_status)->toBe(RunStatus::Succeeded);
            expect(ScheduledJob::where('run_once_schedule_name', $pending->output['aws_schedule_name'])->sole()->last_status)->toBeNull();
        });

        it('falls back to the recurring row by class when the payload carries no schedule name', function () {
            $pending = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at);
            $recurring = recurringExampleRow();
            $payload = schedulePayload($this->client, $pending->output['aws_schedule_name']);
            unset($payload[EventBridgeDriver::PAYLOAD_SCHEDULE_KEY]);

            Event::dispatch(new JobProcessing('sqs', firedJob($payload)));

            expect(ScheduledJobRun::count())->toBe(2);
            $run = ScheduledJobRun::where('scheduled_job_id', $recurring->id)->sole();
            expect($run->status)->toBe(RunStatus::Running);
            expect($run->triggered_by)->toBe(TriggeredBy::Scheduler);
            expect($pending->fresh()->status)->toBe(RunStatus::Pending);
        });

        it('ignores jobs that are not registered with TaskBridge', function () {
            recurringExampleRow();
            $payload = ['uuid' => 'u-1', 'data' => ['commandName' => 'App\\Jobs\\NotRegistered']];

            Event::dispatch(new JobProcessing('sqs', firedJob($payload)));

            expect(ScheduledJobRun::count())->toBe(0);
        });
    });
});
