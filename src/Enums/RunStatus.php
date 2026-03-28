<?php

namespace CodeTechNL\TaskBridge\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return __('taskbridge::enums.run_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'primary',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Skipped => 'warning',
        };
    }
}
