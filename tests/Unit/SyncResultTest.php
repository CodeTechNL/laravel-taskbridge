<?php

use CodeTechNL\TaskBridge\Support\SyncResult;

describe('SyncResult', function () {
    it('starts empty', function () {
        $result = SyncResult::empty();

        expect($result->created)->toBe(0)
            ->and($result->updated)->toBe(0)
            ->and($result->removed)->toBe(0)
            ->and($result->unchanged)->toBe(0);
    });

    it('supports immutable builder methods', function () {
        $result = SyncResult::empty()
            ->withCreated(2)
            ->withUpdated(1)
            ->withRemoved(3)
            ->withUnchanged(5);

        expect($result->created)->toBe(2)
            ->and($result->updated)->toBe(1)
            ->and($result->removed)->toBe(3)
            ->and($result->unchanged)->toBe(5);
    });

    it('can be merged', function () {
        $a = new SyncResult(created: 1, updated: 2, removed: 1, unchanged: 0);
        $b = new SyncResult(created: 0, updated: 1, removed: 0, unchanged: 3);

        $merged = $a->merge($b);

        expect($merged->created)->toBe(1)
            ->and($merged->updated)->toBe(3)
            ->and($merged->removed)->toBe(1)
            ->and($merged->unchanged)->toBe(3);
    });

    it('converts to array', function () {
        $result = new SyncResult(1, 2, 3, 4);

        expect($result->toArray())->toBe([
            'created' => 1,
            'updated' => 2,
            'removed' => 3,
            'unchanged' => 4,
        ]);
    });
});
