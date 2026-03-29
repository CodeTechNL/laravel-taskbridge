<?php

namespace CodeTechNL\TaskBridge\Support;

use ReflectionClass;

class JobInspector
{
    /**
     * Create a job instance without invoking its constructor.
     *
     * Use this whenever you need to read metadata from a job class
     * (cronExpression, taskLabel, group, queue properties like tries/timeout)
     * without requiring the caller to supply constructor arguments.
     *
     * This is safe for all metadata methods because those methods are
     * expected to return static configuration, not instance state built
     * in the constructor.
     */
    public static function make(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * Check whether the class implements a given interface.
     *
     * Uses reflection only — no instance is created.
     */
    public static function implementsInterface(string $class, string $interface): bool
    {
        return (new ReflectionClass($class))->implementsInterface($interface);
    }

    /**
     * Check whether the class declares a given method.
     *
     * Uses reflection only — no instance is created.
     */
    public static function hasMethod(string $class, string $method): bool
    {
        return (new ReflectionClass($class))->hasMethod($method);
    }
}
