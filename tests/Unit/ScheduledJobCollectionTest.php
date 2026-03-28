<?php

use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Support\ScheduledJobCollection;

describe('ScheduledJobCollection', function () {
    it('filters enabled jobs', function () {
        $jobs = new ScheduledJobCollection([
            new ScheduledJob(['enabled' => true, 'identifier' => 'job-a']),
            new ScheduledJob(['enabled' => false, 'identifier' => 'job-b']),
            new ScheduledJob(['enabled' => true, 'identifier' => 'job-c']),
        ]);

        $enabled = $jobs->enabled();

        expect($enabled)->toHaveCount(2);
        expect($enabled->identifiers())->toBe(['job-a', 'job-c']);
    });

    it('filters disabled jobs', function () {
        $jobs = new ScheduledJobCollection([
            new ScheduledJob(['enabled' => true, 'identifier' => 'job-a']),
            new ScheduledJob(['enabled' => false, 'identifier' => 'job-b']),
        ]);

        expect($jobs->disabled())->toHaveCount(1);
    });

    it('filters by group', function () {
        $jobs = new ScheduledJobCollection([
            new ScheduledJob(['group' => 'billing', 'identifier' => 'job-a', 'enabled' => true]),
            new ScheduledJob(['group' => 'notifications', 'identifier' => 'job-b', 'enabled' => true]),
            new ScheduledJob(['group' => 'billing', 'identifier' => 'job-c', 'enabled' => true]),
        ]);

        expect($jobs->byGroup('billing'))->toHaveCount(2);
    });
});
