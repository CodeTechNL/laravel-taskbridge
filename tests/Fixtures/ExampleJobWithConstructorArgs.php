<?php

namespace CodeTechNL\TaskBridge\Tests\Fixtures;

use CodeTechNL\TaskBridge\Contracts\HasCustomLabel;
use CodeTechNL\TaskBridge\Contracts\HasGroup;
use CodeTechNL\TaskBridge\Contracts\RunsConditionally;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A job whose constructor requires arguments that cannot be auto-resolved.
 *
 * This fixture intentionally omits default values so that `new static()`
 * would throw a TypeError. JobInspector::make() bypasses the constructor
 * and must still be able to read metadata and check interfaces.
 */
class ExampleJobWithConstructorArgs implements HasCustomLabel, HasGroup, RunsConditionally, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $tenantId,
        private readonly int $batchSize,
    ) {}

    public function cronExpression(): string
    {
        return '0 3 * * *';
    }

    public function taskLabel(): string
    {
        return 'Example job with constructor args';
    }

    public function group(): string
    {
        return 'Testing';
    }

    public function shouldRun(): bool
    {
        return true;
    }

    public function handle(): void {}
}
