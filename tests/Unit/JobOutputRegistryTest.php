<?php

use CodeTechNL\TaskBridge\Data\JobOutput;
use CodeTechNL\TaskBridge\Support\JobOutputRegistry;

describe('JobOutputRegistry', function () {
    it('stores metadata and returns it as a success JobOutput', function () {
        JobOutputRegistry::store('MyJob', ['count' => 7]);
        $output = JobOutputRegistry::retrieveSuccess('MyJob');

        expect($output)->toBeInstanceOf(JobOutput::class)
            ->and($output->status)->toBe('success')
            ->and($output->metadata)->toBe(['count' => 7]);
    });

    it('returns null when nothing was stored for the class', function () {
        $output = JobOutputRegistry::retrieveSuccess('UnknownJob');

        expect($output)->toBeNull();
    });

    it('clears the entry after a successful retrieval', function () {
        JobOutputRegistry::store('MyJob', ['x' => 1]);
        JobOutputRegistry::retrieveSuccess('MyJob');

        expect(JobOutputRegistry::retrieveSuccess('MyJob'))->toBeNull();
    });

    it('isolates entries by class name', function () {
        JobOutputRegistry::store('JobA', ['a' => 1]);
        JobOutputRegistry::store('JobB', ['b' => 2]);

        expect(JobOutputRegistry::retrieveSuccess('JobA')?->metadata)->toBe(['a' => 1]);
        expect(JobOutputRegistry::retrieveSuccess('JobB')?->metadata)->toBe(['b' => 2]);
    });

    it('builds an error output with the provided message', function () {
        $output = JobOutputRegistry::retrieveError('MyJob', 'Something failed');

        expect($output)->toBeInstanceOf(JobOutput::class)
            ->and($output->status)->toBe('error')
            ->and($output->message)->toBe('Something failed');
    });

    it('clears any stored metadata when retrieving an error', function () {
        JobOutputRegistry::store('MyJob', ['x' => 1]);
        JobOutputRegistry::retrieveError('MyJob', 'fail');

        expect(JobOutputRegistry::retrieveSuccess('MyJob'))->toBeNull();
    });

    it('returns an error output even when nothing was stored', function () {
        $output = JobOutputRegistry::retrieveError('NeverStoredJob', 'oh no');

        expect($output->status)->toBe('error')
            ->and($output->message)->toBe('oh no');
    });
});
