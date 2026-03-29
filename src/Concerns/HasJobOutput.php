<?php

namespace CodeTechNL\TaskBridge\Concerns;

use CodeTechNL\TaskBridge\Support\JobOutputRegistry;

/**
 * Satisfies the reportOutput() requirement of the ReportsTaskOutput interface.
 *
 * Add this trait to any job that implements ReportsTaskOutput. Call
 * $this->reportOutput([...]) inside handle() with the key/value metadata
 * you want stored in the run log. The infrastructure wraps it in a
 * JobOutput DTO automatically.
 *
 *   public function handle(): void
 *   {
 *       $this->reportOutput(['processed' => 42, 'skipped' => 3]);
 *   }
 */
trait HasJobOutput
{
    public function reportOutput(array $metadata): void
    {
        JobOutputRegistry::store(static::class, $metadata);
    }
}
