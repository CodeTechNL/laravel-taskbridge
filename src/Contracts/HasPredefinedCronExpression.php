<?php

namespace CodeTechNL\TaskBridge\Contracts;

/**
 * Implement this interface to provide a default cron expression for the job.
 *
 * When implemented, TaskBridge pre-fills the cron field in the UI and stores
 * the expression as the job's default schedule. The stored value can still
 * be overridden per environment via the cron_override field.
 *
 * When not implemented, the cron must be set manually when creating the
 * job record in the UI — useful when the schedule differs per environment.
 *
 * Example:
 *
 *   class SendWeeklyReport implements ShouldQueue, HasPredefinedCronExpression
 *   {
 *       public function cronExpression(): string
 *       {
 *           return '0 9 * * 1'; // Every Monday at 09:00
 *       }
 *   }
 */
interface HasPredefinedCronExpression
{
    public function cronExpression(): string;
}
