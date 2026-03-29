<?php

namespace CodeTechNL\TaskBridge\Tests\Fixtures;

use CodeTechNL\TaskBridge\Concerns\HasJobOutput;
use CodeTechNL\TaskBridge\Contracts\ReportsTaskOutput;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExampleOutputJob implements ReportsTaskOutput, ShouldQueue
{
    use Dispatchable;
    use HasJobOutput;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function cronExpression(): string
    {
        return '0 * * * *';
    }

    public function handle(): void
    {
        $this->reportOutput(['processed' => 42, 'skipped' => 3]);
    }
}
