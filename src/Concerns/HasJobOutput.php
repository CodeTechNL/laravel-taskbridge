<?php

namespace CodeTechNL\TaskBridge\Concerns;

use CodeTechNL\TaskBridge\Support\JobOutputRegistry;

/**
 * Ready-made implementation for jobs that implement ReportsTaskOutput.
 *
 * Call $this->reportOutput([...]) inside handle() with any key/value
 * metadata you want stored in the run log. The infrastructure wraps
 * it in a JobOutput DTO automatically.
 *
 *   public function handle(): void
 *   {
 *       $this->reportOutput(['processed' => 42, 'skipped' => 3]);
 *   }
 */
trait HasJobOutput
{
    protected function reportOutput(array $metadata): void
    {
        JobOutputRegistry::store(static::class, $metadata);
    }
}
