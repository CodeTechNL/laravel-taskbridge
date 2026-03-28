<?php

use CodeTechNL\TaskBridge\Contracts\ScheduledJob as ScheduledJobContract;
use CodeTechNL\TaskBridge\Support\JobDiscoverer;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleConditionalJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleOutputJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleScheduledJob;

describe('JobDiscoverer', function () {
    it('discovers classes implementing ScheduledJob in a directory', function () {
        $found = JobDiscoverer::discover([__DIR__.'/../Fixtures']);

        expect($found)->toContain(ExampleScheduledJob::class);
    });

    it('discovers all ScheduledJob implementations in the directory', function () {
        $found = JobDiscoverer::discover([__DIR__.'/../Fixtures']);

        expect($found)->toContain(ExampleScheduledJob::class)
            ->and($found)->toContain(ExampleConditionalJob::class)
            ->and($found)->toContain(ExampleOutputJob::class);
    });

    it('only returns classes that implement the ScheduledJob contract', function () {
        $found = JobDiscoverer::discover([__DIR__.'/../Fixtures']);

        foreach ($found as $class) {
            expect(is_a($class, ScheduledJobContract::class, true))->toBeTrue(
                "{$class} does not implement ScheduledJob"
            );
        }
    });

    it('returns an empty array for a non-existent directory', function () {
        $found = JobDiscoverer::discover(['/this/path/does/not/exist']);

        expect($found)->toBe([]);
    });

    it('returns an empty array when no paths are provided', function () {
        $found = JobDiscoverer::discover([]);

        expect($found)->toBe([]);
    });

    it('silently skips non-existent directories and still scans valid ones', function () {
        $found = JobDiscoverer::discover([
            '/nonexistent/path',
            __DIR__.'/../Fixtures',
        ]);

        expect($found)->toContain(ExampleScheduledJob::class);
    });
});
