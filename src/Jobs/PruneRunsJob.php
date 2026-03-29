<?php

namespace CodeTechNL\TaskBridge\Jobs;

use CodeTechNL\TaskBridge\Contracts\HasCustomLabel;
use CodeTechNL\TaskBridge\Contracts\HasGroup;
use CodeTechNL\TaskBridge\Models\ScheduledJobRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deletes run log entries older than the configured retention period.
 *
 * Register this job in config/taskbridge.php under 'jobs' to have it
 * scheduled automatically. The retention period is read from
 * taskbridge.logging.retention_days (default: 30).
 */
class PruneRunsJob implements HasCustomLabel, HasGroup, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function cronExpression(): string
    {
        return '0 3 * * *'; // Daily at 03:00
    }

    public function taskLabel(): string
    {
        return 'Prune Run Logs';
    }

    public function group(): string
    {
        return 'TaskBridge';
    }

    public function handle(): void
    {
        $days = (int) config('taskbridge.logging.retention_days', 30);

        ScheduledJobRun::where('created_at', '<', now()->subDays($days))->delete();
    }
}
