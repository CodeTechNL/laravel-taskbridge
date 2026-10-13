<?php

use CodeTechNL\TaskBridge\Enums\RunStatus;
use CodeTechNL\TaskBridge\Enums\TriggeredBy;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Models\ScheduledJobRun;
use CodeTechNL\TaskBridge\Testing\TaskBridgeFake;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleScheduledJob;

describe('TaskBridge::scheduleOnce', function () {
    beforeEach(function () {
        config()->set('taskbridge.name_prefix', null);
        config()->set('queue.default', 'sqs');
        config()->set('queue.connections.sqs', [
            'driver' => 'sqs',
            'prefix' => 'https://sqs.eu-west-1.amazonaws.com/123456789012',
            'queue' => 'default',
        ]);

        $this->taskBridge = TaskBridgeFake::fake();
        $this->client = $this->taskBridge->schedulerClient();
        $this->at = now()->addDay()->startOfMinute();
    });

    it('records a one-time job row and a pending run by default', function () {
        $run = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at, ['weekly']);

        expect($this->client->created)->toHaveCount(1);
        $scheduleName = $this->client->created[0]['Name'];
        expect($scheduleName)->toMatch('/^once-example-scheduled-job-[a-z0-9]{8}$/');

        $onceRecord = ScheduledJob::sole();
        expect($onceRecord->isOnce())->toBeTrue();
        expect($onceRecord->identifier)->toBe($scheduleName);
        expect($onceRecord->run_once_schedule_name)->toBe($scheduleName);
        expect($onceRecord->run_once_at->equalTo($this->at))->toBeTrue();
        expect($onceRecord->constructor_arguments)->toBe(['weekly']);
        expect($onceRecord->enabled)->toBeFalse();

        expect($run->exists)->toBeTrue();
        expect(ScheduledJobRun::count())->toBe(1);
        expect($run->scheduled_job_id)->toBe($onceRecord->id);
        expect($run->status)->toBe(RunStatus::Pending);
        expect($run->triggered_by)->toBe(TriggeredBy::ScheduledOnce);
        expect($run->output['aws_schedule_name'])->toBe($scheduleName);
        expect($run->output['job_class'])->toBe(ExampleScheduledJob::class);
    });

    it('creates the schedule without any database rows when log is false', function () {
        $run = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at, [42], log: false);

        expect($this->client->created)->toHaveCount(1);
        expect($this->client->created[0]['ActionAfterCompletion'])->toBe('DELETE');
        expect(ScheduledJob::count())->toBe(0);
        expect(ScheduledJobRun::count())->toBe(0);

        // The transient run still tells the caller what was scheduled, and under which name.
        expect($run->exists)->toBeFalse();
        expect($run->status)->toBe(RunStatus::Pending);
        expect($run->triggered_by)->toBe(TriggeredBy::ScheduledOnce);
        expect($run->scheduled_for->equalTo($this->at))->toBeTrue();
        expect($run->output['aws_schedule_name'])->toBe($this->client->created[0]['Name']);
        expect($run->output['job_class'])->toBe(ExampleScheduledJob::class);
    });

    it('writes no rows either when run logging is disabled globally', function () {
        config()->set('taskbridge.logging.enabled', false);

        $run = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at);

        expect($this->client->created)->toHaveCount(1);
        expect(ScheduledJob::count())->toBe(0);
        expect(ScheduledJobRun::count())->toBe(0);
        expect($run->exists)->toBeFalse();
        expect($run->output['aws_schedule_name'])->toBe($this->client->created[0]['Name']);
    });

    it('can be cancelled by name through the driver', function () {
        $run = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at, log: false);

        $this->taskBridge->getEventBridge()->remove($run->output['aws_schedule_name']);

        expect($this->client->deletedNames())->toBe([$run->output['aws_schedule_name']]);
        expect($this->client->names())->toBe([]);
    });

    it('is not removed when the recurring jobs are synced afterwards', function () {
        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'queue_connection' => 'sqs',
            'cron_expression' => '0 * * * *',
            'enabled' => true,
        ]);
        $this->taskBridge->getEventBridge()->sync($this->taskBridge->enabled());

        $run = $this->taskBridge->scheduleOnce(ExampleScheduledJob::class, $this->at, log: false);
        $result = $this->taskBridge->getEventBridge()->sync($this->taskBridge->enabled());

        expect($this->client->deleted)->toBe([]);
        expect($this->client->names())->toBe(['example-scheduled-job', $run->output['aws_schedule_name']]);
        expect($result->removed)->toBe(0);
        expect($result->updated)->toBe(1);
    });

    it('rejects a class that does not exist', function () {
        $this->taskBridge->scheduleOnce('App\\Jobs\\DoesNotExist', $this->at, log: false);
    })->throws(InvalidArgumentException::class, 'Class not found');
});
