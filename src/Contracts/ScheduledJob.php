<?php

namespace CodeTechNL\TaskBridge\Contracts;

interface ScheduledJob
{
    /**
     * The cron expression for this job.
     * This is the default — can be overridden from the database.
     */
    public function cronExpression(): string;
}
