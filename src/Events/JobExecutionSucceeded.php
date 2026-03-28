<?php

namespace CodeTechNL\TaskBridge\Events;

use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Models\ScheduledJobRun;

class JobExecutionSucceeded
{
    public function __construct(
        public readonly ScheduledJob $job,
        public readonly ScheduledJobRun $run,
    ) {}
}
