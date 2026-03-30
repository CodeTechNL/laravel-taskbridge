<?php

namespace CodeTechNL\TaskBridge\Tests\Fixtures;

use CodeTechNL\TaskBridge\Attributes\SchedulableJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job carrying a bare #[SchedulableJob] attribute with no parameters.
 * Used to verify that the attribute works as a discovery marker without
 * providing any metadata (all attribute properties should be null).
 */
#[SchedulableJob]
class ExampleAttributeMarkerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void {}
}
