<?php

namespace CodeTechNL\TaskBridge\Tests\Fixtures;

use CodeTechNL\TaskBridge\Concerns\HasJobOutput;
use CodeTechNL\TaskBridge\Contracts\HasPredefinedCronExpression;
use CodeTechNL\TaskBridge\Contracts\ReportsTaskOutput;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExampleOutputJob implements HasPredefinedCronExpression, ReportsTaskOutput, ShouldQueue
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
        // Simulate reporting once per item, then appending a summary.
        foreach (range(1, 42) as $i) {
            $this->pushToReport('processed', $i);
        }

        foreach (range(1, 3) as $i) {
            $this->pushToReport('skipped', $i);
        }

        $this->pushToReport('total', count($this->getOutputFromReport('processed', [])));
    }
}
