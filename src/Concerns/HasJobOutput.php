<?php

namespace CodeTechNL\TaskBridge\Concerns;

use CodeTechNL\TaskBridge\Support\JobOutputRegistry;

/**
 * Satisfies the reportOutput() requirement of the ReportsTaskOutput interface.
 *
 * reportOutput() can be called any number of times inside handle(). Each call
 * is merged into the accumulated bag rather than replacing it. When the same
 * key is reported more than once the values are stacked into an array.
 *
 *   // Called once per item inside a loop:
 *   $this->reportOutput(['send_to' => $company->owner->email]);
 *
 *   // Read back what was accumulated so far to derive a summary:
 *   $this->reportOutput(['total_count' => count($this->getOutputFromReport('send_to', []))]);
 */
trait HasJobOutput
{
    public function reportOutput(array $metadata): void
    {
        JobOutputRegistry::accumulate(static::class, $metadata);
    }

    /**
     * Push a single key/value pair into the accumulated output bag.
     *
     * Shorthand for reportOutput([$key => $value]) — convenient inside loops:
     *
     *   $this->pushToReport('send_to', $company->owner->email);
     */
    public function pushToReport(string $key, mixed $value): void
    {
        JobOutputRegistry::accumulate(static::class, [$key => $value]);
    }

    /**
     * Read the accumulated output bag without clearing it.
     *
     * With no arguments returns the full bag as an array.
     * With a key returns the value for that key, or $default when absent.
     */
    public function getOutputFromReport(?string $key = null, mixed $default = null): mixed
    {
        $bag = JobOutputRegistry::peek(static::class);

        if ($key === null) {
            return $bag;
        }

        return array_key_exists($key, $bag) ? $bag[$key] : $default;
    }
}
