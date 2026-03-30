<?php

namespace CodeTechNL\TaskBridge\Concerns;

/**
 * Skip job execution when the application is in maintenance mode.
 *
 * Include this trait in any ShouldQueue job to have TaskBridge automatically
 * skip (and log as "Skipped") execution whenever the application is down for
 * maintenance. The RunsConditionally check still runs afterwards, so both
 * conditions are respected independently.
 *
 * Usage:
 *
 *   class SendDailyReport implements ShouldQueue
 *   {
 *       use SkipOnMaintenance;
 *
 *       public function handle(): void { ... }
 *   }
 *
 * To also add a custom runtime condition, implement RunsConditionally alongside
 * the trait. TaskBridge checks maintenance mode first, then shouldRun():
 *
 *   class SendDailyReport implements RunsConditionally, ShouldQueue
 *   {
 *       use SkipOnMaintenance;
 *
 *       public function shouldRun(): bool
 *       {
 *           return $this->someCondition();
 *       }
 *   }
 */
trait SkipOnMaintenance
{
    public function shouldSkipInMaintenanceMode(): bool
    {
        return true;
    }
}
