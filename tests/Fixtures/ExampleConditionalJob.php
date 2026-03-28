<?php

namespace CodeTechNL\TaskBridge\Tests\Fixtures;

use CodeTechNL\TaskBridge\Contracts\ConditionalJob;
use CodeTechNL\TaskBridge\Contracts\ScheduledJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExampleConditionalJob implements ConditionalJob, ScheduledJob, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly bool $shouldRun = true,
    ) {}

    public function cronExpression(): string
    {
        return '0 9 * * *';
    }

    public function shouldRun(): bool
    {
        return $this->shouldRun;
    }

    public function handle(): void
    {
        // Example implementation
    }
}
