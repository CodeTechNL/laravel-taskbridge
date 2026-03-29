<?php

use CodeTechNL\TaskBridge\Enums\RunStatus;
use CodeTechNL\TaskBridge\Enums\TriggeredBy;
use CodeTechNL\TaskBridge\Events\JobExecutionFailed;
use CodeTechNL\TaskBridge\Events\JobExecutionSkipped;
use CodeTechNL\TaskBridge\Events\JobExecutionStarted;
use CodeTechNL\TaskBridge\Events\JobExecutionSucceeded;
use CodeTechNL\TaskBridge\Middleware\TaskBridgeMiddleware;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Models\ScheduledJobRun;
use CodeTechNL\TaskBridge\TaskBridge;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleConditionalJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleOutputJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleScheduledJob;
use Illuminate\Support\Facades\Event;

describe('TaskBridgeMiddleware', function () {
    beforeEach(function () {
        $this->middleware = new TaskBridgeMiddleware;

        // Register fixture jobs so the middleware's isRegistered() check passes.
        app(TaskBridge::class)->register([
            ExampleScheduledJob::class,
            ExampleConditionalJob::class,
            ExampleOutputJob::class,
        ]);
    });

    // ── Success path ───────────────────────────────────────────────────────────

    it('creates a run record on successful execution', function () {
        $record = ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $this->middleware->handle(new ExampleScheduledJob, fn ($j) => $j->handle());

        expect(ScheduledJobRun::where('scheduled_job_id', $record->id)->count())->toBe(1);
        expect(ScheduledJobRun::first()->status)->toBe(RunStatus::Succeeded);
    });

    it('sets triggered_by to Scheduler on a normal run', function () {
        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $this->middleware->handle(new ExampleScheduledJob, fn ($j) => $j->handle());

        expect(ScheduledJobRun::first()->triggered_by)->toBe(TriggeredBy::Scheduler);
    });

    it('records started_at, finished_at and duration_ms', function () {
        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $this->middleware->handle(new ExampleScheduledJob, fn ($j) => $j->handle());

        $run = ScheduledJobRun::first();
        expect($run->started_at)->not->toBeNull();
        expect($run->finished_at)->not->toBeNull();
        expect($run->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0);
    });

    it('updates last_status and last_run_at on the job record after success', function () {
        $record = ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $this->middleware->handle(new ExampleScheduledJob, fn ($j) => $j->handle());

        $record->refresh();
        expect($record->last_status)->toBe(RunStatus::Succeeded);
        expect($record->last_run_at)->not->toBeNull();
    });

    // ── Skipped path ───────────────────────────────────────────────────────────

    it('marks run as skipped when job is disabled', function () {
        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => false,
        ]);

        $this->middleware->handle(new ExampleScheduledJob, fn ($j) => $j->handle());

        $run = ScheduledJobRun::first();
        expect($run->status)->toBe(RunStatus::Skipped);
        expect($run->skipped_reason)->toBe('Job is disabled');
    });

    it('updates last_status on the job record after a disabled skip', function () {
        $record = ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => false,
        ]);

        $this->middleware->handle(new ExampleScheduledJob, fn ($j) => $j->handle());

        $record->refresh();
        expect($record->last_status)->toBe(RunStatus::Skipped);
    });

    it('marks run as skipped when shouldRun returns false', function () {
        ScheduledJob::create([
            'class' => ExampleConditionalJob::class,
            'identifier' => 'example-conditional-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $this->middleware->handle(new ExampleConditionalJob(shouldRun: false), fn ($j) => $j->handle());

        $run = ScheduledJobRun::first();
        expect($run->status)->toBe(RunStatus::Skipped);
        expect($run->skipped_reason)->toBe('shouldRun() returned false');
    });

    it('runs the job when ConditionalJob::shouldRun returns true', function () {
        ScheduledJob::create([
            'class' => ExampleConditionalJob::class,
            'identifier' => 'example-conditional-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $this->middleware->handle(new ExampleConditionalJob(shouldRun: true), fn ($j) => $j->handle());

        expect(ScheduledJobRun::first()->status)->toBe(RunStatus::Succeeded);
    });

    // ── Failed path ────────────────────────────────────────────────────────────

    it('marks run as failed on exception', function () {
        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        try {
            $this->middleware->handle(new ExampleScheduledJob, function () {
                throw new RuntimeException('Something went wrong');
            });
        } catch (RuntimeException) {
            // expected
        }

        $run = ScheduledJobRun::first();
        expect($run->status)->toBe(RunStatus::Failed);
        expect($run->output['status'])->toBe('error');
        expect($run->output['message'])->toBe('Something went wrong');
    });

    it('updates last_status on the job record after failure', function () {
        $record = ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        try {
            $this->middleware->handle(new ExampleScheduledJob, function () {
                throw new RuntimeException('Error');
            });
        } catch (RuntimeException) {
        }

        $record->refresh();
        expect($record->last_status)->toBe(RunStatus::Failed);
    });

    it('re-throws the exception after marking run as failed', function () {
        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        expect(fn () => $this->middleware->handle(new ExampleScheduledJob, function () {
            throw new RuntimeException('re-thrown');
        }))->toThrow(RuntimeException::class, 're-thrown');
    });

    // ── Output capture ─────────────────────────────────────────────────────────

    it('captures structured output from jobs that implement ReportsOutput', function () {
        ScheduledJob::create([
            'class' => ExampleOutputJob::class,
            'identifier' => 'example-output-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $this->middleware->handle(new ExampleOutputJob, fn ($j) => $j->handle());

        $run = ScheduledJobRun::first();
        expect($run->output)->not->toBeNull();
        expect($run->output['status'])->toBe('success');
        // reportOutput() was called once per item — values are stacked into arrays.
        expect($run->output['metadata']['processed'])->toHaveCount(42);
        expect($run->output['metadata']['skipped'])->toHaveCount(3);
        expect($run->output['metadata']['total'])->toBe(42);
    });

    it('stores null output when job does not report output', function () {
        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $this->middleware->handle(new ExampleScheduledJob, fn ($j) => $j->handle());

        expect(ScheduledJobRun::first()->output)->toBeNull();
    });

    // ── Pass-through cases ─────────────────────────────────────────────────────

    it('passes through non-scheduled jobs without creating a run', function () {
        $called = false;
        $plain = new stdClass;

        $this->middleware->handle($plain, function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeTrue();
        expect(ScheduledJobRun::count())->toBe(0);
    });

    it('passes through scheduled jobs that are not registered in the database', function () {
        $called = false;

        $this->middleware->handle(new ExampleScheduledJob, function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeTrue();
        expect(ScheduledJobRun::count())->toBe(0);
    });

    // ── Logging disabled ───────────────────────────────────────────────────────

    it('still runs the job when logging is disabled', function () {
        config()->set('taskbridge.logging.enabled', false);

        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $called = false;
        $this->middleware->handle(new ExampleScheduledJob, function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeTrue();
        expect(ScheduledJobRun::count())->toBe(0);
    });

    it('does not run the job when logging is disabled and job is disabled', function () {
        config()->set('taskbridge.logging.enabled', false);

        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => false,
        ]);

        $called = false;
        $this->middleware->handle(new ExampleScheduledJob, function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();
        expect(ScheduledJobRun::count())->toBe(0);
    });

    it('does not run the job when logging is disabled and shouldRun returns false', function () {
        config()->set('taskbridge.logging.enabled', false);

        ScheduledJob::create([
            'class' => ExampleConditionalJob::class,
            'identifier' => 'example-conditional-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $called = false;
        $this->middleware->handle(new ExampleConditionalJob(shouldRun: false), function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();
    });

    // ── Events ─────────────────────────────────────────────────────────────────

    it('dispatches JobExecutionStarted and JobExecutionSucceeded on success', function () {
        Event::fake([JobExecutionStarted::class, JobExecutionSucceeded::class]);

        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        $this->middleware->handle(new ExampleScheduledJob, fn ($j) => $j->handle());

        Event::assertDispatched(JobExecutionStarted::class);
        Event::assertDispatched(JobExecutionSucceeded::class);
    });

    it('dispatches JobExecutionSkipped when job is disabled', function () {
        Event::fake([JobExecutionStarted::class, JobExecutionSkipped::class]);

        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => false,
        ]);

        $this->middleware->handle(new ExampleScheduledJob, fn ($j) => $j->handle());

        Event::assertDispatched(JobExecutionSkipped::class);
        Event::assertNotDispatched(JobExecutionSucceeded::class);
    });

    it('dispatches JobExecutionFailed on exception', function () {
        Event::fake([JobExecutionStarted::class, JobExecutionFailed::class]);

        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => 'example-scheduled-job',
            'cron_expression' => '* * * * *',
            'enabled' => true,
        ]);

        try {
            $this->middleware->handle(new ExampleScheduledJob, function () {
                throw new RuntimeException('fail');
            });
        } catch (RuntimeException) {
        }

        Event::assertDispatched(JobExecutionFailed::class);
        Event::assertNotDispatched(JobExecutionSucceeded::class);
    });
});
