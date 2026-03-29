<?php

use CodeTechNL\TaskBridge\Enums\RunStatus;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Support\ScheduledJobCollection;

describe('ScheduledJob model', function () {
    describe('identifierFromClass', function () {
        beforeEach(function () {
            // Disable prefix so these tests focus on the class-name conversion only.
            config()->set('taskbridge.name_prefix', null);
        });

        it('converts a fully-qualified class name to a kebab-case identifier', function () {
            expect(ScheduledJob::identifierFromClass('App\\Jobs\\SendTrialExpiredNotifications'))
                ->toBe('send-trial-expired-notifications');
        });

        it('strips the namespace and uses only the class basename', function () {
            expect(ScheduledJob::identifierFromClass('CodeTechNL\\TaskBridge\\Jobs\\PruneRunsJob'))
                ->toBe('prune-runs-job');
        });

        it('handles a class with no namespace', function () {
            expect(ScheduledJob::identifierFromClass('MySimpleJob'))
                ->toBe('my-simple-job');
        });

        it('prepends the name_prefix when set', function () {
            config()->set('taskbridge.name_prefix', 'production');

            expect(ScheduledJob::identifierFromClass('App\\Jobs\\SendDailyReport'))
                ->toBe('production-send-daily-report');
        });
    });

    describe('effective_cron attribute', function () {
        it('returns cron_expression when no override is set', function () {
            $job = new ScheduledJob([
                'cron_expression' => '0 * * * *',
                'cron_override' => null,
            ]);

            expect($job->effective_cron)->toBe('0 * * * *');
        });

        it('returns cron_override when one is set', function () {
            $job = new ScheduledJob([
                'cron_expression' => '0 * * * *',
                'cron_override' => '0 3 * * *',
            ]);

            expect($job->effective_cron)->toBe('0 3 * * *');
        });
    });

    describe('newCollection', function () {
        it('returns a ScheduledJobCollection instance', function () {
            $collection = (new ScheduledJob)->newCollection([]);

            expect($collection)->toBeInstanceOf(ScheduledJobCollection::class);
        });
    });

    describe('last_status Eloquent cast', function () {
        it('casts last_status string to RunStatus enum on retrieval', function () {
            $record = ScheduledJob::create([
                'class' => 'App\\Jobs\\ExampleJob',
                'identifier' => 'example-job',
                'cron_expression' => '* * * * *',
                'enabled' => true,
                'last_status' => 'succeeded',
            ]);

            expect($record->fresh()->last_status)->toBe(RunStatus::Succeeded);
        });

        it('stores a RunStatus enum and retrieves it correctly', function () {
            $record = ScheduledJob::create([
                'class' => 'App\\Jobs\\ExampleJob2',
                'identifier' => 'example-job-2',
                'cron_expression' => '* * * * *',
                'enabled' => true,
            ]);

            $record->update(['last_status' => RunStatus::Failed]);

            expect($record->fresh()->last_status)->toBe(RunStatus::Failed);
        });

        it('returns null when last_status has not been set', function () {
            $record = ScheduledJob::create([
                'class' => 'App\\Jobs\\ExampleJob3',
                'identifier' => 'example-job-3',
                'cron_expression' => '* * * * *',
                'enabled' => true,
            ]);

            expect($record->fresh()->last_status)->toBeNull();
        });
    });
});
