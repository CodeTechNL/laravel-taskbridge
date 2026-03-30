<?php

use CodeTechNL\TaskBridge\Commands\ImportSchedulesCommand;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleComplexConstructorJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleJobWithConstructorArgs;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleScheduledJob;

describe('taskbridge:import-schedules', function () {
    it('outputs an info message when no schedules are configured', function () {
        config()->set('taskbridge.schedules', []);

        $this->artisan('taskbridge:import-schedules')
            ->assertSuccessful()
            ->expectsOutputToContain('No schedules defined');
    });

    it('imports a valid schedule without constructor arguments', function () {
        config()->set('taskbridge.schedules', [
            ExampleScheduledJob::class => ['cron' => '0 9 * * *', 'arguments' => []],
        ]);

        $this->artisan('taskbridge:import-schedules')->assertSuccessful();

        $this->assertDatabaseHas('taskbridge_jobs', [
            'class' => ExampleScheduledJob::class,
            'cron_expression' => '0 9 * * *',
        ]);
    });

    it('imports a valid schedule with constructor arguments', function () {
        config()->set('taskbridge.schedules', [
            ExampleJobWithConstructorArgs::class => [
                'cron' => '0 3 * * *',
                'arguments' => ['tenant-1', 100],
            ],
        ]);

        $this->artisan('taskbridge:import-schedules')->assertSuccessful();

        $record = ScheduledJob::where('class', ExampleJobWithConstructorArgs::class)->first();

        expect($record)->not->toBeNull()
            ->and($record->cron_expression)->toBe('0 3 * * *')
            ->and($record->constructor_arguments)->toBe(['tenant-1', 100]);
    });

    it('skips an entry that is not an array', function () {
        config()->set('taskbridge.schedules', [
            ExampleScheduledJob::class => '0 9 * * *',
        ]);

        $this->artisan('taskbridge:import-schedules')
            ->assertFailed()
            ->expectsOutputToContain("Entry must be an array with a 'cron' key");

        $this->assertDatabaseMissing('taskbridge_jobs', ['class' => ExampleScheduledJob::class]);
    });

    it('exits with failure when at least one entry fails', function () {
        config()->set('taskbridge.schedules', [
            'App\\Jobs\\NonExistentJob' => ['cron' => '0 9 * * *', 'arguments' => []],
        ]);

        $this->artisan('taskbridge:import-schedules')->assertFailed();
    });

    it('skips a class that does not exist and continues with valid entries', function () {
        config()->set('taskbridge.schedules', [
            'App\\Jobs\\NonExistentJob' => ['cron' => '0 9 * * *', 'arguments' => []],
            ExampleScheduledJob::class => ['cron' => '0 3 * * *', 'arguments' => []],
        ]);

        $this->artisan('taskbridge:import-schedules')->assertFailed();

        $this->assertDatabaseHas('taskbridge_jobs', ['class' => ExampleScheduledJob::class]);
        $this->assertDatabaseMissing('taskbridge_jobs', ['class' => 'App\\Jobs\\NonExistentJob']);
    });

    it('skips a job with a non-scalar constructor', function () {
        config()->set('taskbridge.schedules', [
            ExampleComplexConstructorJob::class => ['cron' => '0 9 * * *', 'arguments' => []],
            ExampleScheduledJob::class => ['cron' => '0 6 * * *', 'arguments' => []],
        ]);

        $this->artisan('taskbridge:import-schedules')->assertFailed();

        $this->assertDatabaseHas('taskbridge_jobs', ['class' => ExampleScheduledJob::class]);
        $this->assertDatabaseMissing('taskbridge_jobs', ['class' => ExampleComplexConstructorJob::class]);
    });

    it('skips an invalid cron expression', function () {
        config()->set('taskbridge.schedules', [
            ExampleScheduledJob::class => ['cron' => 'not-a-cron', 'arguments' => []],
        ]);

        $this->artisan('taskbridge:import-schedules')
            ->assertFailed()
            ->expectsOutputToContain('Invalid cron expression');
    });

    it('skips when too few arguments are provided for the constructor', function () {
        config()->set('taskbridge.schedules', [
            ExampleJobWithConstructorArgs::class => ['cron' => '0 3 * * *', 'arguments' => []],
        ]);

        $this->artisan('taskbridge:import-schedules')
            ->assertFailed()
            ->expectsOutputToContain('Too few arguments');

        $this->assertDatabaseMissing('taskbridge_jobs', ['class' => ExampleJobWithConstructorArgs::class]);
    });

    it('skips when too many arguments are provided for the constructor', function () {
        config()->set('taskbridge.schedules', [
            ExampleJobWithConstructorArgs::class => ['cron' => '0 3 * * *', 'arguments' => ['t', 1, 'extra']],
        ]);

        $this->artisan('taskbridge:import-schedules')
            ->assertFailed()
            ->expectsOutputToContain('Too many arguments');
    });

    it('updates an existing record when the cron expression changes', function () {
        ScheduledJob::create([
            'class' => ExampleScheduledJob::class,
            'identifier' => ScheduledJob::identifierFromClass(ExampleScheduledJob::class),
            'cron_expression' => '0 0 * * *',
            'enabled' => false,
        ]);

        config()->set('taskbridge.schedules', [
            ExampleScheduledJob::class => ['cron' => '30 8 * * 1', 'arguments' => []],
        ]);

        $this->artisan('taskbridge:import-schedules')->assertSuccessful();

        $this->assertDatabaseHas('taskbridge_jobs', [
            'class' => ExampleScheduledJob::class,
            'cron_expression' => '30 8 * * 1',
        ]);

        expect(ScheduledJob::where('class', ExampleScheduledJob::class)->count())->toBe(1);
    });

    it('reports the count of imported and failed entries', function () {
        config()->set('taskbridge.schedules', [
            ExampleScheduledJob::class => ['cron' => '0 9 * * *', 'arguments' => []],
            'App\\Jobs\\Ghost' => ['cron' => '0 0 * * *', 'arguments' => []],
        ]);

        $this->artisan('taskbridge:import-schedules')
            ->assertFailed()
            ->expectsOutputToContain('Imported 1 schedule(s), 1 failed.');
    });
});

describe('ImportSchedulesCommand::parseEntry()', function () {
    it('returns cron and arguments from the array', function () {
        expect(ImportSchedulesCommand::parseEntry(['cron' => '0 9 * * *', 'arguments' => ['a', 1]]))
            ->toBe(['0 9 * * *', ['a', 1]]);
    });

    it('returns empty arguments when the key is omitted', function () {
        expect(ImportSchedulesCommand::parseEntry(['cron' => '0 9 * * *']))
            ->toBe(['0 9 * * *', []]);
    });
});

describe('ImportSchedulesCommand::validateArguments()', function () {
    it('returns null for a no-arg job with no arguments', function () {
        expect(ImportSchedulesCommand::validateArguments(ExampleScheduledJob::class, []))->toBeNull();
    });

    it('returns null for correct argument count', function () {
        expect(ImportSchedulesCommand::validateArguments(ExampleJobWithConstructorArgs::class, ['t', 1]))->toBeNull();
    });

    it('returns error string when too few arguments', function () {
        expect(ImportSchedulesCommand::validateArguments(ExampleJobWithConstructorArgs::class, []))
            ->toContain('Too few');
    });

    it('returns error string when too many arguments', function () {
        expect(ImportSchedulesCommand::validateArguments(ExampleJobWithConstructorArgs::class, ['t', 1, 'x']))
            ->toContain('Too many');
    });
});
