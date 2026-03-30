<?php

namespace CodeTechNL\TaskBridge\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A job whose constructor has a non-scalar (object) parameter.
 * Used to verify that ImportSchedulesCommand rejects incompatible jobs.
 */
class ExampleComplexConstructorJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly \stdClass $config,
    ) {}

    public function handle(): void {}
}
