<?php

use CodeTechNL\TaskBridge\Enums\RunStatus;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Support\ScheduledJobCollection;
use Illuminate\Support\Str;

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

        it('returns the identifier unchanged when it is exactly 64 characters', function () {
            // Craft a class name whose kebab basename is exactly 64 chars (no prefix).
            $sixtyFourChars = str_repeat('a', 64);
            $class = "App\\Jobs\\{$sixtyFourChars}";

            $identifier = ScheduledJob::identifierFromClass($class);

            expect(strlen($identifier))->toBe(64);
            expect($identifier)->toBe(str_repeat('a', 64));
        });

        it('replaces the class-name part with its MD5 hash when the identifier exceeds 64 characters', function () {
            // 65-char basename → kebab → 65 chars → triggers MD5 fallback.
            $longClass = 'App\\Jobs\\'.str_repeat('A', 65);
            $expectedHash = md5(Str::kebab(str_repeat('A', 65)));

            $identifier = ScheduledJob::identifierFromClass($longClass);

            expect($identifier)->toBe($expectedHash);
            expect(strlen($identifier))->toBeLessThanOrEqual(64);
        });

        it('replaces with MD5 and still prepends the name_prefix', function () {
            config()->set('taskbridge.name_prefix', 'prod');

            // 65-char class name → kebab → 65 chars → triggers MD5 fallback.
            $longClass = 'App\\Jobs\\'.str_repeat('A', 65);
            $expectedHash = md5(Str::kebab(str_repeat('A', 65)));

            $identifier = ScheduledJob::identifierFromClass($longClass);

            expect($identifier)->toBe("prod-{$expectedHash}");
            expect(strlen($identifier))->toBeLessThanOrEqual(64);
        });

        it('does not include the name_prefix in the MD5 hash', function () {
            config()->set('taskbridge.name_prefix', 'staging');

            $longClass = 'App\\Jobs\\'.str_repeat('B', 65);
            $bareHash = md5(Str::kebab(str_repeat('B', 65)));
            $withPrefix = "staging-{$bareHash}";
            $withoutPrefixHash = md5('staging-'.Str::kebab(str_repeat('B', 65)));

            $identifier = ScheduledJob::identifierFromClass($longClass);

            expect($identifier)->toBe($withPrefix);
            expect($identifier)->not->toBe("staging-{$withoutPrefixHash}");
        });

        it('throws a RuntimeException when even prefix + MD5 exceeds 64 characters', function () {
            // MD5 = 32 chars. prefix + "-" + MD5 = prefix + 33.
            // To exceed 64: prefix must be > 31 chars.
            config()->set('taskbridge.name_prefix', str_repeat('x', 32));

            $longClass = 'App\\Jobs\\'.str_repeat('Z', 65);

            expect(fn () => ScheduledJob::identifierFromClass($longClass))
                ->toThrow(RuntimeException::class, 'exceeds 64 characters even after MD5 hashing');
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
