<?php

namespace CodeTechNL\TaskBridge\Events;

use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Models\ScheduledJobRun;

class JobExecutionStarted
{
    public function __construct(
        public readonly ScheduledJob $job,
        public readonly ScheduledJobRun $run,
    ) {}
}
