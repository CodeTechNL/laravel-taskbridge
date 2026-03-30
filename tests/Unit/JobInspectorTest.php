<?php

use CodeTechNL\TaskBridge\Attributes\SchedulableJob;
use CodeTechNL\TaskBridge\Contracts\HasCustomLabel;
use CodeTechNL\TaskBridge\Contracts\HasGroup;
use CodeTechNL\TaskBridge\Contracts\RunsConditionally;
use CodeTechNL\TaskBridge\Support\JobInspector;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleAttributeJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleAttributeMarkerJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleConditionalJob;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleJobWithConstructorArgs;
use CodeTechNL\TaskBridge\Tests\Fixtures\ExampleScheduledJob;
use Illuminate\Contracts\Queue\ShouldQueue;

describe('JobInspector', function () {
    // ── make() ─────────────────────────────────────────────────────────────────

    describe('make()', function () {
        it('returns an instance of the given class', function () {
            $instance = JobInspector::make(ExampleScheduledJob::class);

            expect($instance)->toBeInstanceOf(ExampleScheduledJob::class);
        });

        it('does not throw when the constructor has required arguments', function () {
            // Plain `new ExampleJobWithConstructorArgs()` would throw a TypeError
            // because tenantId and batchSize have no defaults.
            expect(fn () => JobInspector::make(ExampleJobWithConstructorArgs::class))
                ->not->toThrow(TypeError::class);
        });

        it('returns an instance that passes interface checks', function () {
            $instance = JobInspector::make(ExampleJobWithConstructorArgs::class);

            expect($instance)->toBeInstanceOf(ShouldQueue::class)
                ->and($instance)->toBeInstanceOf(HasCustomLabel::class)
                ->and($instance)->toBeInstanceOf(HasGroup::class)
                ->and($instance)->toBeInstanceOf(RunsConditionally::class);
        });

        it('can call cronExpression() on the no-constructor instance', function () {
            $instance = JobInspector::make(ExampleJobWithConstructorArgs::class);

            expect($instance->cronExpression())->toBe('0 3 * * *');
        });

        it('can call taskLabel() on the no-constructor instance', function () {
            $instance = JobInspector::make(ExampleJobWithConstructorArgs::class);

            expect($instance->taskLabel())->toBe('Example job with constructor args');
        });

        it('can call group() on the no-constructor instance', function () {
            $instance = JobInspector::make(ExampleJobWithConstructorArgs::class);

            expect($instance->group())->toBe('Testing');
        });

        it('can call cronExpression() on a job with an optional constructor', function () {
            // ExampleConditionalJob has a default-value constructor — make() is
            // still the correct path (consistent, no special-casing).
            $instance = JobInspector::make(ExampleConditionalJob::class);

            expect($instance->cronExpression())->toBe('0 9 * * *');
        });
    });

    // ── implementsInterface() ──────────────────────────────────────────────────

    describe('implementsInterface()', function () {
        it('returns true when the class implements the interface', function () {
            expect(JobInspector::implementsInterface(ExampleScheduledJob::class, ShouldQueue::class))
                ->toBeTrue();
        });

        it('returns true for every implemented interface on a multi-interface class', function () {
            expect(JobInspector::implementsInterface(ExampleJobWithConstructorArgs::class, ShouldQueue::class))
                ->toBeTrue()
                ->and(JobInspector::implementsInterface(ExampleJobWithConstructorArgs::class, HasCustomLabel::class))
                ->toBeTrue()
                ->and(JobInspector::implementsInterface(ExampleJobWithConstructorArgs::class, HasGroup::class))
                ->toBeTrue()
                ->and(JobInspector::implementsInterface(ExampleJobWithConstructorArgs::class, RunsConditionally::class))
                ->toBeTrue();
        });

        it('returns false when the class does not implement the interface', function () {
            expect(JobInspector::implementsInterface(ExampleScheduledJob::class, HasCustomLabel::class))
                ->toBeFalse();
        });

        it('works without instantiating the class (no constructor side-effects)', function () {
            // If implementsInterface() called new(), this would throw a TypeError.
            expect(fn () => JobInspector::implementsInterface(
                ExampleJobWithConstructorArgs::class,
                ShouldQueue::class
            ))->not->toThrow(TypeError::class);
        });
    });

    // ── getSchedulableJobAttribute() ───────────────────────────────────────────

    describe('getSchedulableJobAttribute()', function () {
        it('returns null for a class without the attribute', function () {
            expect(JobInspector::getSchedulableJobAttribute(ExampleScheduledJob::class))
                ->toBeNull();
        });

        it('returns a SchedulableJob instance for a class with the attribute', function () {
            expect(JobInspector::getSchedulableJobAttribute(ExampleAttributeJob::class))
                ->toBeInstanceOf(SchedulableJob::class);
        });

        it('reads the name from the attribute', function () {
            $attr = JobInspector::getSchedulableJobAttribute(ExampleAttributeJob::class);

            expect($attr->name)->toBe('Attribute Job');
        });

        it('reads the group from the attribute', function () {
            $attr = JobInspector::getSchedulableJobAttribute(ExampleAttributeJob::class);

            expect($attr->group)->toBe('Attribute Group');
        });

        it('reads the cron from the attribute', function () {
            $attr = JobInspector::getSchedulableJobAttribute(ExampleAttributeJob::class);

            expect($attr->cron)->toBe('0 5 * * *');
        });

        it('returns an instance with all null properties for a bare marker attribute', function () {
            $attr = JobInspector::getSchedulableJobAttribute(ExampleAttributeMarkerJob::class);

            expect($attr)->toBeInstanceOf(SchedulableJob::class)
                ->and($attr->name)->toBeNull()
                ->and($attr->group)->toBeNull()
                ->and($attr->cron)->toBeNull();
        });
    });

    // ── hasMethod() ────────────────────────────────────────────────────────────

    describe('hasMethod()', function () {
        it('returns true when the method exists', function () {
            expect(JobInspector::hasMethod(ExampleScheduledJob::class, 'cronExpression'))
                ->toBeTrue()
                ->and(JobInspector::hasMethod(ExampleScheduledJob::class, 'handle'))
                ->toBeTrue();
        });

        it('returns false when the method does not exist', function () {
            expect(JobInspector::hasMethod(ExampleScheduledJob::class, 'taskLabel'))
                ->toBeFalse()
                ->and(JobInspector::hasMethod(ExampleScheduledJob::class, 'nonExistentMethod'))
                ->toBeFalse();
        });

        it('works without instantiating the class (no constructor side-effects)', function () {
            expect(fn () => JobInspector::hasMethod(
                ExampleJobWithConstructorArgs::class,
                'cronExpression'
            ))->not->toThrow(TypeError::class);
        });
    });
});
