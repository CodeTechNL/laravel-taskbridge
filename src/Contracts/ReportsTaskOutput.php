<?php

namespace CodeTechNL\TaskBridge\Contracts;

/**
 * Contract for jobs that report structured output to the TaskBridge run log.
 *
 * Implementing this interface requires a reportOutput() method. Add the
 * HasJobOutput trait to your job class to get the implementation for free:
 *
 *   class ImportProducts implements ShouldQueue, ReportsTaskOutput
 *   {
 *       use HasJobOutput;
 *
 *       public function handle(): void
 *       {
 *           // ... your logic ...
 *           $this->reportOutput(['processed' => 100, 'skipped' => 3]);
 *       }
 *   }
 *
 * On success the metadata is stored in the run log.
 * On failure TaskBridge automatically records an error output — no action needed in the job.
 */
interface ReportsTaskOutput
{
    /**
     * Store structured metadata to be written to the run log on completion.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function reportOutput(array $metadata): void;
}
