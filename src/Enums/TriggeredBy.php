<?php

namespace CodeTechNL\TaskBridge\Enums;

enum TriggeredBy: string
{
    case Scheduler = 'scheduler';
    case Manual = 'manual';
    case DryRun = 'dry_run';
    case ScheduledOnce = 'scheduled_once';

    public function label(): string
    {
        return __('taskbridge::enums.triggered_by.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduler => 'gray',
            self::Manual => 'primary',
            self::DryRun => 'warning',
            self::ScheduledOnce => 'info',
        };
    }
}
