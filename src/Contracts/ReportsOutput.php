<?php

namespace CodeTechNL\TaskBridge\Contracts;

/**
 * Marker interface — signals that this job reports structured output
 * to the TaskBridge run log.
 *
 * Use the HasJobOutput trait to get the reportOutput() method:
 *
 *   class MyJob implements ScheduledJob, ReportsOutput
 *   {
 *       use HasJobOutput;
 *
 *       public function handle(): void
 *       {
 *           $this->reportOutput(JobOutput::success('Done', ['processed' => 42]));
 *       }
 *   }
 */
interface ReportsOutput {}
