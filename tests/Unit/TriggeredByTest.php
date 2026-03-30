<?php

use CodeTechNL\TaskBridge\Enums\TriggeredBy;

describe('TriggeredBy', function () {
    it('has the correct string values', function () {
        expect(TriggeredBy::Scheduler->value)->toBe('scheduler')
            ->and(TriggeredBy::Manual->value)->toBe('manual')
            ->and(TriggeredBy::DryRun->value)->toBe('dry_run')
            ->and(TriggeredBy::ScheduledOnce->value)->toBe('scheduled_once');
    });

    it('has exactly four cases', function () {
        expect(TriggeredBy::cases())->toHaveCount(4);
    });

    it('can be created from a string value', function () {
        expect(TriggeredBy::from('scheduler'))->toBe(TriggeredBy::Scheduler)
            ->and(TriggeredBy::from('manual'))->toBe(TriggeredBy::Manual)
            ->and(TriggeredBy::from('dry_run'))->toBe(TriggeredBy::DryRun);
    });

    it('returns null for an unknown value via tryFrom', function () {
        expect(TriggeredBy::tryFrom('unknown'))->toBeNull();
    });

    it('maps each case to the correct UI color', function () {
        expect(TriggeredBy::Scheduler->color())->toBe('gray')
            ->and(TriggeredBy::Manual->color())->toBe('primary')
            ->and(TriggeredBy::DryRun->color())->toBe('warning')
            ->and(TriggeredBy::ScheduledOnce->color())->toBe('info');
    });

    it('can be created from the scheduled_once value', function () {
        expect(TriggeredBy::from('scheduled_once'))->toBe(TriggeredBy::ScheduledOnce);
    });
});
