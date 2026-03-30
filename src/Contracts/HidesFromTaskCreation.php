<?php

namespace CodeTechNL\TaskBridge\Contracts;

/**
 * Implement this interface to hide the job from the task creation dropdown.
 *
 * Useful for internal or system jobs that should only be registered
 * programmatically and never created manually through the UI.
 *
 * Example:
 *
 *   class PruneRunsJob implements ShouldQueue, HidesFromTaskCreation
 *   {
 *       // This job will not appear in the "Create task" job selector.
 *   }
 */
interface HidesFromTaskCreation {}
