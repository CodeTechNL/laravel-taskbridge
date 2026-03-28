<?php

namespace CodeTechNL\TaskBridge\Events;

use CodeTechNL\TaskBridge\Models\ScheduledJob;

class JobRemoved
{
    public function __construct(
        public readonly ScheduledJob $job,
    ) {}
}
