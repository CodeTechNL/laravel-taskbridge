<?php

use CodeTechNL\TaskBridge\Enums\RunStatus;

describe('RunStatus', function () {
    it('has the correct string values', function () {
        expect(RunStatus::Pending->value)->toBe('pending')
            ->and(RunStatus::Running->value)->toBe('running')
            ->and(RunStatus::Succeeded->value)->toBe('succeeded')
            ->and(RunStatus::Failed->value)->toBe('failed')
            ->and(RunStatus::Skipped->value)->toBe('skipped');
    });

    it('has exactly five cases', function () {
        expect(RunStatus::cases())->toHaveCount(5);
    });

    it('can be created from a string value', function () {
        expect(RunStatus::from('succeeded'))->toBe(RunStatus::Succeeded)
            ->and(RunStatus::from('failed'))->toBe(RunStatus::Failed)
            ->and(RunStatus::from('skipped'))->toBe(RunStatus::Skipped)
            ->and(RunStatus::from('pending'))->toBe(RunStatus::Pending)
            ->and(RunStatus::from('running'))->toBe(RunStatus::Running);
    });

    it('returns null for an unknown value via tryFrom', function () {
        expect(RunStatus::tryFrom('unknown'))->toBeNull();
    });

    it('maps each case to the correct UI color', function () {
        expect(RunStatus::Pending->color())->toBe('gray')
            ->and(RunStatus::Running->color())->toBe('primary')
            ->and(RunStatus::Succeeded->color())->toBe('success')
            ->and(RunStatus::Failed->color())->toBe('danger')
            ->and(RunStatus::Skipped->color())->toBe('warning');
    });
});
