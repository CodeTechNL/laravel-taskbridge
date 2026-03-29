<?php

namespace CodeTechNL\TaskBridge\Support;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

class JobInspector
{
    /**
     * The PHP types we consider "simple" for constructor parameters.
     * Only these types (plus untyped params) are allowed for a job to be listed.
     */
    private const SIMPLE_TYPES = ['bool', 'int', 'float', 'string'];

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

    /**
     * Return the constructor parameters for a class.
     * Returns an empty array when the class has no constructor.
     *
     * @return ReflectionParameter[]
     */
    public static function getConstructorParameters(string $class): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        return $constructor?->getParameters() ?? [];
    }

    /**
     * Return true when every constructor parameter is a scalar type
     * (bool, int, float, string) or has no type hint at all.
     * A class with no constructor is also considered simple.
     */
    public static function hasSimpleConstructor(string $class): bool
    {
        foreach (self::getConstructorParameters($class) as $param) {
            if (! self::isSimpleParameter($param)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return true when a single constructor parameter is scalar or untyped.
     */
    public static function isSimpleParameter(ReflectionParameter $param): bool
    {
        $type = $param->getType();

        // No type hint — allow it (treated as string in forms)
        if ($type === null) {
            return true;
        }

        // Union / intersection types are never simple
        if (! ($type instanceof ReflectionNamedType)) {
            return false;
        }

        return in_array($type->getName(), self::SIMPLE_TYPES, strict: true);
    }
}
