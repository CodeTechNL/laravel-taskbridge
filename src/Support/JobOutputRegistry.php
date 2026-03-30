<?php

namespace CodeTechNL\TaskBridge\Support;

use CodeTechNL\TaskBridge\Data\JobOutput;

/**
 * Static registry that bridges the gap between a running queue job and
 * the JobProcessed event listener, which has no access to the job instance.
 */
class JobOutputRegistry
{
    private static array $bag = [];

    /**
     * Clear all accumulated state. Use in test tearDown / beforeEach.
     */
    public static function flush(): void
    {
        self::$bag = [];
    }

    /**
     * Merge $metadata into the accumulated bag for this class.
     *
     * Keys that do not exist yet are set as-is. Keys that already exist are
     * stacked: two scalars become a two-element array, a scalar appended to
     * an existing array, or two arrays merged together.
     *
     * This means reportOutput() can be called multiple times inside handle()
     * and all values are preserved rather than the last call winning.
     */
    public static function accumulate(string $class, array $metadata): void
    {
        $existing = self::$bag[$class] ?? [];

        foreach ($metadata as $key => $value) {
            if (! array_key_exists($key, $existing)) {
                $existing[$key] = $value;
            } elseif (is_array($existing[$key])) {
                $existing[$key] = is_array($value)
                    ? array_merge($existing[$key], $value)
                    : [...$existing[$key], $value];
            } else {
                $existing[$key] = is_array($value)
                    ? [$existing[$key], ...$value]
                    : [$existing[$key], $value];
            }
        }

        self::$bag[$class] = $existing;
    }

    /**
     * Append a single value to a list key for this class.
     *
     * Unlike accumulate(), the key is always stored as an array — even on the
     * very first call. This guarantees that getOutputFromReport('key', [])
     * always returns a type-safe array, making count() / foreach safe without
     * any wrapping on the caller side.
     *
     * Use this inside loops where you collect one item per iteration:
     *
     *   $this->appendToReport('send_to', $company->owner->email);
     *
     * Mixing appendItem() and accumulate() on the same key is supported:
     * accumulate() will merge into the existing array as usual.
     */
    public static function appendItem(string $class, string $key, mixed $value): void
    {
        $existing = self::$bag[$class] ?? [];
        $current = $existing[$key] ?? [];

        if (! is_array($current)) {
            $current = [$current];
        }

        $current[] = $value;
        $existing[$key] = $current;
        self::$bag[$class] = $existing;
    }

    /**
     * Read the current accumulated bag without clearing it.
     *
     * Used by HasJobOutput::getOutputFromReport() so the job can inspect
     * what it has reported so far (e.g. to derive a total_count).
     *
     * @return array<string, mixed>
     */
    public static function peek(string $class): array
    {
        return self::$bag[$class] ?? [];
    }

    /**
     * Build a success JobOutput from stored metadata, or null if nothing was stored.
     */
    public static function retrieveSuccess(string $class): ?JobOutput
    {
        $metadata = self::$bag[$class] ?? null;
        unset(self::$bag[$class]);

        if ($metadata === null) {
            return null;
        }

        return JobOutput::success('Success', $metadata);
    }

    /**
     * Build an error JobOutput and clear any stored metadata for that class.
     */
    public static function retrieveError(string $class, string $errorMessage): JobOutput
    {
        unset(self::$bag[$class]);

        return JobOutput::error($errorMessage);
    }
}
