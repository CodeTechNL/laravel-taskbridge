<?php

namespace CodeTechNL\TaskBridge\Contracts;

/**
 * Implement this interface to assign the job to a named group in the TaskBridge UI.
 *
 * When not implemented, TaskBridge auto-detects the group from the job's folder name.
 * Implementing this interface always takes priority over the folder-based detection.
 *
 * Example:
 *
 *   class SendReport implements ShouldQueue, HasGroup
 *   {
 *       public function group(): string
 *       {
 *           return 'Reporting';
 *       }
 *   }
 */
interface HasGroup
{
    public function group(): string;
}
