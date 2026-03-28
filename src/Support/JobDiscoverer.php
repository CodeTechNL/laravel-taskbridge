<?php

namespace CodeTechNL\TaskBridge\Support;

use CodeTechNL\TaskBridge\Contracts\ScheduledJob;
use Symfony\Component\Finder\Finder;

class JobDiscoverer
{
    /**
     * Scan the given directories and return every non-abstract class
     * that implements the ScheduledJob contract.
     *
     * @param  string[]  $paths
     * @return string[]
     */
    public static function discover(array $paths): array
    {
        $classes = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $files = Finder::create()->files()->name('*.php')->in($path);

            foreach ($files as $file) {
                $class = self::classFromFile($file->getRealPath());

                if (! $class) {
                    continue;
                }

                try {
                    if (! class_exists($class)) {
                        continue;
                    }

                    $reflection = new \ReflectionClass($class);

                    if (! $reflection->isAbstract() && $reflection->implementsInterface(ScheduledJob::class)) {
                        $classes[] = $class;
                    }
                } catch (\Throwable) {
                    // Unloadable class — skip silently
                }
            }
        }

        return $classes;
    }

    /**
     * Extract the fully-qualified class name from a PHP file by parsing
     * its namespace and class declarations.
     */
    private static function classFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);

        if (! preg_match('/^namespace\s+(.+?);/m', $contents, $namespaceMatch)) {
            return null;
        }

        if (! preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+(\w+)/m', $contents, $classMatch)) {
            return null;
        }

        return $namespaceMatch[1].'\\'.$classMatch[1];
    }
}
