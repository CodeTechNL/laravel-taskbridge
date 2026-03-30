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
     * Shorthand for reportOutput([$key => $value]) — convenient for scalar
     * summary values. Note: the first call sets the value as-is (scalar);
     * subsequent calls on the same key will stack them into an array.
     * Use appendToReport() when building a list inside a loop.
     *
     *   $this->pushToReport('total_count', $count);
     */
    public function pushToReport(string $key, mixed $value): void
    {
        JobOutputRegistry::accumulate(static::class, [$key => $value]);
    }

    /**
     * Append a single value to a list key — always stores as an array.
     *
     * Unlike pushToReport(), the very first call already wraps the value in
     * an array, so getOutputFromReport('key', []) always returns a proper
     * array regardless of how many times this was called. Safe to pass to
     * count() or iterate over without any extra wrapping.
     *
     * Designed for use inside loops where you collect one item per iteration:
     *
     *   $companies->each(function (Company $company) {
     *       $this->appendToReport('send_to', $company->owner->email);
     *   });
     *
     *   $this->reportOutput([
     *       'total_count' => count($this->getOutputFromReport('send_to', [])),
     *   ]);
     */
    public function appendToReport(string $key, mixed $value): void
    {
        JobOutputRegistry::appendItem(static::class, $key, $value);
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
