<?php

namespace CodeTechNL\TaskBridge\Contracts;

/**
 * Implement this interface when the job should decide at runtime whether to run.
 *
 * TaskBridge calls shouldRun() before executing handle(). Return false to silently
 * skip the run — it will be logged as "Skipped" in the run history.
 *
 * Example:
 *
 *   class SendReport implements ShouldQueue, RunsConditionally
 *   {
 *       public function shouldRun(): bool
 *       {
 *           return ! app()->isMaintenanceMode();
 *       }
 *   }
 */
interface RunsConditionally
{
    public function shouldRun(): bool;
}
