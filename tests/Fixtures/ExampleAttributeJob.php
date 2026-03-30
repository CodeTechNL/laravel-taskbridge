<?php

namespace CodeTechNL\TaskBridge\Tests\Fixtures;

use CodeTechNL\TaskBridge\Attributes\SchedulableJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job carrying a fully-populated #[SchedulableJob] attribute.
 * Used to verify that attribute values are read correctly.
 */
#[SchedulableJob(name: 'Attribute Job', group: 'Attribute Group', cron: '0 5 * * *')]
class ExampleAttributeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void {}
}
