<?php

namespace CodeTechNL\TaskBridge\Contracts;

/**
 * Marker interface — signals that this job reports structured output to the TaskBridge run log.
 *
 * Use together with the HasJobOutput trait to get the reportOutput() helper:
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
interface ReportsTaskOutput {}
