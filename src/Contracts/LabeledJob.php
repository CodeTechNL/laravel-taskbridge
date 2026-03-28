<?php

namespace CodeTechNL\TaskBridge\Contracts;

interface LabeledJob
{
    /**
     * Human-readable label used in the Filament UI dropdown and run history.
     * Example: 'Send Trial Expired Notifications'
     */
    public function taskLabel(): string;
}
