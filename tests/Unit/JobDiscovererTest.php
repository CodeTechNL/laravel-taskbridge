<?php

use CodeTechNL\TaskBridge\Support\JobDiscoverer;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleConditionalJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleOutputJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleScheduledJob;
use Illuminate\Contracts\Queue\ShouldQueue;

describe('JobDiscoverer', function () {
    it('discovers ShouldQueue jobs in a directory', function () {
        $found = JobDiscoverer::discover([__DIR__.'/../Fixtures']);

        expect($found)->toContain(ExampleScheduledJob::class);
    });

    it('discovers all ShouldQueue jobs in the directory', function () {
        $found = JobDiscoverer::discover([__DIR__.'/../Fixtures']);

        expect($found)->toContain(ExampleScheduledJob::class)
            ->and($found)->toContain(ExampleConditionalJob::class)
            ->and($found)->toContain(ExampleOutputJob::class);
    });

    it('only returns classes that implement ShouldQueue', function () {
        $found = JobDiscoverer::discover([__DIR__.'/../Fixtures']);

        foreach ($found as $class) {
            expect(is_a($class, ShouldQueue::class, true))->toBeTrue(
                "{$class} does not implement ShouldQueue"
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
