<?php

namespace CodeTechNL\TaskBridge\Events;

use CodeTechNL\TaskBridge\Models\ScheduledJob;

class JobSynced
{
    public function __construct(
        public readonly ScheduledJob $job,
        public readonly string $action, // 'created', 'updated', 'unchanged'
    ) {}
}
