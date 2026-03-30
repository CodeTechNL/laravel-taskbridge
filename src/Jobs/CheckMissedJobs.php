<?php

namespace CodeTechNL\TaskBridge\Jobs;

use CodeTechNL\TaskBridge\Attributes\SchedulableJob;
use CodeTechNL\TaskBridge\Contracts\HasCustomLabel;
use CodeTechNL\TaskBridge\Contracts\HasGroup;
use CodeTechNL\TaskBridge\Contracts\HasPredefinedCronExpression;
use CodeTechNL\TaskBridge\Events\JobMissed;
use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Support\CronTranslator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;

#[SchedulableJob(name: 'Check Missed Jobs', group: 'TaskBridge', cron: '0 * * * *')]
class CheckMissedJobs implements HasCustomLabel, HasGroup, HasPredefinedCronExpression, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function cronExpression(): string
    {
        return '0 * * * *'; // Every hour
    }

    public function taskLabel(): string
    {
        return 'Check Missed Jobs';
    }

    public function group(): string
    {
        return 'TaskBridge';
    }

    public function handle(): void
    {
        if (! config('taskbridge.monitoring.notify_on_miss', true)) {
            return;
        }

        $jobs = ScheduledJob::where('enabled', true)->get();

        foreach ($jobs as $job) {
            try {
                $this->checkJob($job);
            } catch (\Throwable) {
                // Never let a check failure kill the whole job
                continue;
            }
        }
    }

    private function checkJob(ScheduledJob $job): void
    {
        if (! CronTranslator::isValid($job->effective_cron)) {
            return;
        }

        $expectedAt = CronTranslator::previousRunAt($job->effective_cron);
        $nextExpected = CronTranslator::nextRunAt($job->effective_cron);

        // Compute the cron interval in seconds
        $intervalSeconds = $nextExpected->getTimestamp() - $expectedAt->getTimestamp();

        // If we haven't heard from the job within 2× the cron interval, consider it missed
        $threshold = $expectedAt->getTimestamp() - $intervalSeconds;

        $lastRun = $job->last_run_at?->getTimestamp();

        if ($lastRun === null || $lastRun < $threshold) {
            Event::dispatch(new JobMissed($job, $expectedAt));
        }
    }
}
