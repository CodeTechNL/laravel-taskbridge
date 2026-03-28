<?php

namespace CodeTechNL\TaskBridge\Support;

use CodeTechNL\TaskBridge\Models\ScheduledJob;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends Collection<int, ScheduledJob>
 */
class ScheduledJobCollection extends Collection
{
    public function enabled(): self
    {
        return $this->filter(fn (ScheduledJob $job) => $job->enabled)->values();
    }

    public function disabled(): self
    {
        return $this->filter(fn (ScheduledJob $job) => ! $job->enabled)->values();
    }

    public function byGroup(string $group): self
    {
        return $this->filter(fn (ScheduledJob $job) => $job->group === $group)->values();
    }

    public function identifiers(): array
    {
        return $this->pluck('identifier')->all();
    }
}
