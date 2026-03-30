<?php

namespace CodeTechNL\TaskBridge\Tests\Fixtures;

use CodeTechNL\TaskBridge\Attributes\SchedulableJob;

/**
 * Job carrying #[SchedulableJob] but NOT implementing ShouldQueue.
 * Used to verify that attribute-mode discovery does not require ShouldQueue,
 * while interface-mode discovery correctly excludes this class.
 */
#[SchedulableJob(name: 'No Queue Job', group: 'Testing')]
class ExampleAttributeJobWithoutShouldQueue
{
    public function handle(): void {}
}
