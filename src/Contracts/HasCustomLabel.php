<?php

namespace CodeTechNL\TaskBridge\Contracts;

/**
 * Implement this interface to provide a human-readable display name in the TaskBridge UI.
 *
 * When not implemented, TaskBridge auto-derives the label from the class name:
 * e.g. SendTrialExpiredNotifications → "Send trial expired notifications".
 *
 * Example:
 *
 *   class SendTrialExpiredNotifications implements ShouldQueue, HasCustomLabel
 *   {
 *       public function taskLabel(): string
 *       {
 *           return 'Trial Expired — Send notification';
 *       }
 *   }
 */
interface HasCustomLabel
{
    public function taskLabel(): string;
}
