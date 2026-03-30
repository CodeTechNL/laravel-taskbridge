<?php

namespace CodeTechNL\TaskBridge\Jobs;

use CodeTechNL\TaskBridge\Contracts\HasCustomLabel;
use CodeTechNL\TaskBridge\Contracts\HasGroup;
use CodeTechNL\TaskBridge\Contracts\HasPredefinedCronExpression;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deletes one-time scheduled job rows older than the given number of days.
 *
 * A one-time job row is considered expired once its run_once_at timestamp
 * is older than $retentionDays. This keeps the Scheduled Jobs table clean
 * without removing pending future runs.
 *
 * Register this job in config/taskbridge.php under 'jobs' to have it
 * scheduled automatically.
 */
class PruneOnceSchedulesJob implements HasCustomLabel, HasGroup, HasPredefinedCronExpression, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  int|null  $retentionDays  Number of days to retain completed one-time schedules.
     *                                   Pass null to fall back to taskbridge.logging.retention_days.
     */
    public function __construct(
        public readonly ?int $retentionDays = null,
    ) {}

    public function cronExpression(): string
    {
        return '0 3 * * *'; // Daily at 03:00
    }

    public function taskLabel(): string
    {
        return 'Prune One-Time Schedules';
    }

    public function group(): string
    {
        return 'TaskBridge';
    }

    public function handle(): void
    {
        $days = $this->retentionDays ?? (int) config('taskbridge.logging.retention_days', 30);

        ScheduledJob::whereNotNull('run_once_at')
            ->where('run_once_at', '<', now()->subDays($days))
            ->delete();
    }
}
