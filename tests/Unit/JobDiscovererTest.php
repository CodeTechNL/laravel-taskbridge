<?php

use CodeTechNL\TaskBridge\Support\JobDiscoverer;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleAttributeJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleAttributeJobWithoutShouldQueue;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleAttributeMarkerJob;
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

    it('does not include classes carrying #[SchedulableJob] that lack ShouldQueue', function () {
        $found = JobDiscoverer::discover([__DIR__.'/../Fixtures']);

        expect($found)->not->toContain(ExampleAttributeJobWithoutShouldQueue::class);
    });
});

describe('JobDiscoverer::discoverByAttribute()', function () {
    it('discovers classes carrying the #[SchedulableJob] attribute', function () {
        $found = JobDiscoverer::discoverByAttribute([__DIR__.'/../Fixtures']);

        expect($found)->toContain(ExampleAttributeJob::class);
    });

    it('discovers a bare marker attribute with no parameters', function () {
        $found = JobDiscoverer::discoverByAttribute([__DIR__.'/../Fixtures']);

        expect($found)->toContain(ExampleAttributeMarkerJob::class);
    });

    it('discovers classes without ShouldQueue when they carry the attribute', function () {
        $found = JobDiscoverer::discoverByAttribute([__DIR__.'/../Fixtures']);

        expect($found)->toContain(ExampleAttributeJobWithoutShouldQueue::class);
    });

    it('does not include ShouldQueue classes that lack the attribute', function () {
        $found = JobDiscoverer::discoverByAttribute([__DIR__.'/../Fixtures']);

        expect($found)->not->toContain(ExampleScheduledJob::class)
            ->and($found)->not->toContain(ExampleConditionalJob::class)
            ->and($found)->not->toContain(ExampleOutputJob::class);
    });

    it('returns an empty array for a non-existent directory', function () {
        expect(JobDiscoverer::discoverByAttribute(['/this/path/does/not/exist']))
            ->toBe([]);
    });

    it('returns an empty array when no paths are provided', function () {
        expect(JobDiscoverer::discoverByAttribute([]))->toBe([]);
    });

    it('silently skips non-existent directories and still scans valid ones', function () {
        $found = JobDiscoverer::discoverByAttribute([
            '/nonexistent/path',
            __DIR__.'/../Fixtures',
        ]);

        expect($found)->toContain(ExampleAttributeJob::class);
    });
});
