<?php

namespace CodeTechNL\TaskBridge\Events;

use CodeTechNL\TaskBridge\Models\ScheduledJob;

class JobMissed
{
    public function __construct(
        public readonly ScheduledJob $job,
        public readonly \DateTimeImmutable $expectedAt,
    ) {}
}
