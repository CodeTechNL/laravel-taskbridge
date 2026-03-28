<?php

namespace CodeTechNL\TaskBridge\Facades;

use CodeTechNL\TaskBridge\Models\ScheduledJobRun;
use CodeTechNL\TaskBridge\Support\ScheduledJobCollection;
use CodeTechNL\TaskBridge\Support\SyncResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void register(array $jobClasses)
 * @method static void enable(string $jobClass)
 * @method static void disable(string $jobClass)
 * @method static void overrideCron(string $jobClass, string $cron)
 * @method static void resetCron(string $jobClass)
 * @method static ScheduledJobRun run(string $jobClass, bool $dryRun = false, bool $force = false)
 * @method static ScheduledJobCollection all()
 * @method static ScheduledJobCollection enabled()
 * @method static SyncResult sync()
 * @method static string[] getRegisteredClasses()
 *
 * @see \CodeTechNL\TaskBridge\TaskBridge
 */
class TaskBridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \CodeTechNL\TaskBridge\TaskBridge::class;
    }
}
