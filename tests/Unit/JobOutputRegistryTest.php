<?php

use CodeTechNL\TaskBridge\Data\JobOutput;
use CodeTechNL\TaskBridge\Support\JobOutputRegistry;

describe('JobOutputRegistry', function () {
    beforeEach(fn () => JobOutputRegistry::flush());

    // ── accumulate() ───────────────────────────────────────────────────────────

    describe('accumulate()', function () {
        it('stores metadata and returns it as a success JobOutput', function () {
            JobOutputRegistry::accumulate('MyJob', ['count' => 7]);
            $output = JobOutputRegistry::retrieveSuccess('MyJob');

            expect($output)->toBeInstanceOf(JobOutput::class)
                ->and($output->status)->toBe('success')
                ->and($output->metadata)->toBe(['count' => 7]);
        });

        it('sets a new key as-is on first call', function () {
            JobOutputRegistry::accumulate('MyJob', ['processed' => 42]);

            expect(JobOutputRegistry::peek('MyJob'))->toBe(['processed' => 42]);
        });

        it('stacks two scalar values under the same key into an array', function () {
            JobOutputRegistry::accumulate('MyJob', ['send_to' => 'alice@example.com']);
            JobOutputRegistry::accumulate('MyJob', ['send_to' => 'bob@example.com']);

            expect(JobOutputRegistry::peek('MyJob')['send_to'])
                ->toBe(['alice@example.com', 'bob@example.com']);
        });

        it('appends a scalar to an existing array value', function () {
            JobOutputRegistry::accumulate('MyJob', ['send_to' => 'alice@example.com']);
            JobOutputRegistry::accumulate('MyJob', ['send_to' => 'bob@example.com']);
            JobOutputRegistry::accumulate('MyJob', ['send_to' => 'carol@example.com']);

            expect(JobOutputRegistry::peek('MyJob')['send_to'])
                ->toBe(['alice@example.com', 'bob@example.com', 'carol@example.com']);
        });

        it('merges two array values under the same key', function () {
            JobOutputRegistry::accumulate('MyJob', ['tags' => ['a', 'b']]);
            JobOutputRegistry::accumulate('MyJob', ['tags' => ['c', 'd']]);

            expect(JobOutputRegistry::peek('MyJob')['tags'])->toBe(['a', 'b', 'c', 'd']);
        });

        it('wraps an existing scalar when merged with a new array value', function () {
            JobOutputRegistry::accumulate('MyJob', ['send_to' => 'alice@example.com']);
            JobOutputRegistry::accumulate('MyJob', ['send_to' => ['bob@example.com', 'carol@example.com']]);

            expect(JobOutputRegistry::peek('MyJob')['send_to'])
                ->toBe(['alice@example.com', 'bob@example.com', 'carol@example.com']);
        });

        it('does not interfere with independent keys in the same call', function () {
            JobOutputRegistry::accumulate('MyJob', ['send_to' => 'alice@example.com']);
            JobOutputRegistry::accumulate('MyJob', ['send_to' => 'bob@example.com', 'total_count' => 2]);

            $bag = JobOutputRegistry::peek('MyJob');
            expect($bag['send_to'])->toBe(['alice@example.com', 'bob@example.com'])
                ->and($bag['total_count'])->toBe(2);
        });

        it('isolates entries by class name', function () {
            JobOutputRegistry::accumulate('JobA', ['a' => 1]);
            JobOutputRegistry::accumulate('JobB', ['b' => 2]);

            expect(JobOutputRegistry::retrieveSuccess('JobA')?->metadata)->toBe(['a' => 1]);
            expect(JobOutputRegistry::retrieveSuccess('JobB')?->metadata)->toBe(['b' => 2]);
        });
    });

    // ── peek() ─────────────────────────────────────────────────────────────────

    describe('peek()', function () {
        it('returns the current bag without clearing it', function () {
            JobOutputRegistry::accumulate('MyJob', ['x' => 1]);

            $first = JobOutputRegistry::peek('MyJob');
            $second = JobOutputRegistry::peek('MyJob');

            expect($first)->toBe(['x' => 1])
                ->and($second)->toBe(['x' => 1]);
        });

        it('returns an empty array when nothing has been accumulated', function () {
            expect(JobOutputRegistry::peek('UnknownJob'))->toBe([]);
        });
    });

    // ── retrieveSuccess() ──────────────────────────────────────────────────────

    describe('retrieveSuccess()', function () {
        it('returns null when nothing was stored for the class', function () {
            expect(JobOutputRegistry::retrieveSuccess('UnknownJob'))->toBeNull();
        });

        it('clears the entry after retrieval', function () {
            JobOutputRegistry::accumulate('MyJob', ['x' => 1]);
            JobOutputRegistry::retrieveSuccess('MyJob');

            expect(JobOutputRegistry::retrieveSuccess('MyJob'))->toBeNull();
        });

        it('returns the full accumulated bag as success metadata', function () {
            JobOutputRegistry::accumulate('MyJob', ['send_to' => 'alice@example.com']);
            JobOutputRegistry::accumulate('MyJob', ['send_to' => 'bob@example.com']);
            JobOutputRegistry::accumulate('MyJob', ['total_count' => 2]);

            $output = JobOutputRegistry::retrieveSuccess('MyJob');

            expect($output->metadata)->toBe([
                'send_to' => ['alice@example.com', 'bob@example.com'],
                'total_count' => 2,
            ]);
        });
    });

    // ── retrieveError() ────────────────────────────────────────────────────────

    describe('retrieveError()', function () {
        it('builds an error output with the provided message', function () {
            $output = JobOutputRegistry::retrieveError('MyJob', 'Something failed');

            expect($output)->toBeInstanceOf(JobOutput::class)
                ->and($output->status)->toBe('error')
                ->and($output->message)->toBe('Something failed');
        });

        it('clears any accumulated metadata when retrieving an error', function () {
            JobOutputRegistry::accumulate('MyJob', ['x' => 1]);
            JobOutputRegistry::retrieveError('MyJob', 'fail');

            expect(JobOutputRegistry::retrieveSuccess('MyJob'))->toBeNull();
        });

        it('returns an error output even when nothing was stored', function () {
            $output = JobOutputRegistry::retrieveError('NeverStoredJob', 'oh no');

            expect($output->status)->toBe('error')
                ->and($output->message)->toBe('oh no');
        });
    });
});
