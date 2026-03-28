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

    public static function store(string $class, array $metadata): void
    {
        self::$bag[$class] = $metadata;
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
