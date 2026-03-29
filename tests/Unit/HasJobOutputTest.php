<?php

use CodeTechNL\TaskBridge\Concerns\HasJobOutput;
use CodeTechNL\TaskBridge\Contracts\ReportsTaskOutput;
use CodeTechNL\TaskBridge\Support\JobOutputRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;

// Minimal inline job that uses the trait — no real queue wiring needed.
$job = new class implements ReportsTaskOutput, ShouldQueue
{
    use HasJobOutput;
};

describe('HasJobOutput', function () use ($job) {
    beforeEach(fn () => JobOutputRegistry::flush());

    // ── pushToReport() ─────────────────────────────────────────────────────────

    describe('pushToReport()', function () use ($job) {
        it('stores a single key/value pair', function () use ($job) {
            $job->pushToReport('email', 'alice@example.com');

            expect($job->getOutputFromReport('email'))->toBe('alice@example.com');
        });

        it('stacks repeated calls under the same key into an array', function () use ($job) {
            $job->pushToReport('email', 'alice@example.com');
            $job->pushToReport('email', 'bob@example.com');
            $job->pushToReport('email', 'carol@example.com');

            expect($job->getOutputFromReport('email'))
                ->toBe(['alice@example.com', 'bob@example.com', 'carol@example.com']);
        });

        it('keeps independent keys separate', function () use ($job) {
            $job->pushToReport('email', 'alice@example.com');
            $job->pushToReport('status', 'sent');

            expect($job->getOutputFromReport('email'))->toBe('alice@example.com')
                ->and($job->getOutputFromReport('status'))->toBe('sent');
        });

        it('is equivalent to reportOutput() with a single-key array', function () use ($job) {
            $job->pushToReport('x', 42);
            $job->reportOutput(['x' => 99]);

            expect($job->getOutputFromReport('x'))->toBe([42, 99]);
        });
    });

    // ── getOutputFromReport() ──────────────────────────────────────────────────

    describe('getOutputFromReport()', function () use ($job) {
        it('returns the full bag when called with no arguments', function () use ($job) {
            $job->pushToReport('a', 1);
            $job->pushToReport('b', 2);

            expect($job->getOutputFromReport())->toBe(['a' => 1, 'b' => 2]);
        });

        it('returns the value for a given key', function () use ($job) {
            $job->pushToReport('count', 7);

            expect($job->getOutputFromReport('count'))->toBe(7);
        });

        it('returns the default when the key is absent', function () use ($job) {
            expect($job->getOutputFromReport('missing', 'fallback'))->toBe('fallback');
        });

        it('returns null as default when no default is given', function () use ($job) {
            expect($job->getOutputFromReport('missing'))->toBeNull();
        });

        it('does not clear the bag', function () use ($job) {
            $job->pushToReport('x', 1);
            $job->getOutputFromReport();
            $job->getOutputFromReport();

            expect($job->getOutputFromReport('x'))->toBe(1);
        });
    });
});
