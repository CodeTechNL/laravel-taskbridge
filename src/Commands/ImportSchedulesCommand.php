<?php

namespace CodeTechNL\TaskBridge\Commands;

use CodeTechNL\TaskBridge\Models\ScheduledJob;
use CodeTechNL\TaskBridge\Support\JobInspector;
use Cron\CronExpression;
use Illuminate\Console\Command;

class ImportSchedulesCommand extends Command
{
    protected $signature = 'taskbridge:import-schedules';

    protected $description = 'Import predefined schedules from taskbridge.schedules config into the database';

    public function handle(): int
    {
        $schedules = config('taskbridge.schedules', []);

        if (empty($schedules)) {
            $this->components->info('No schedules defined in taskbridge.schedules.');

            return self::SUCCESS;
        }

        $jobModel = config('taskbridge.models.scheduled_job', ScheduledJob::class);

        $imported = 0;
        $failed = 0;

        foreach ($schedules as $class => $value) {
            $label = class_basename($class);

            if (! is_array($value) || ! array_key_exists('cron', $value)) {
                $this->components->error("[{$label}] Entry must be an array with a 'cron' key.");
                $failed++;

                continue;
            }

            [$cron, $arguments] = self::parseEntry($value);

            if (! class_exists($class)) {
                $this->components->error("[{$label}] Class does not exist: {$class}");
                $failed++;

                continue;
            }

            if (! JobInspector::hasSimpleConstructor($class)) {
                $incompatible = implode(', ', JobInspector::getIncompatibleConstructorParams($class));
                $this->components->error("[{$label}] Constructor has non-scalar parameters: {$incompatible}");
                $failed++;

                continue;
            }

            if (! self::isValidCron($cron)) {
                $this->components->error("[{$label}] Invalid cron expression: {$cron}");
                $failed++;

                continue;
            }

            $argError = self::validateArguments($class, $arguments);
            if ($argError !== null) {
                $this->components->error("[{$label}] {$argError}");
                $failed++;

                continue;
            }

            try {
                $identifier = $jobModel::identifierFromClass($class);
            } catch (\RuntimeException $e) {
                $this->components->error("[{$label}] {$e->getMessage()}");
                $failed++;

                continue;
            }

            $jobModel::updateOrCreate(
                ['identifier' => $identifier],
                [
                    'class' => $class,
                    'cron_expression' => $cron,
                    'constructor_arguments' => $arguments ?: null,
                ]
            );

            $this->components->twoColumnDetail($label, "<fg=green>{$cron}</>");
            $imported++;
        }

        $this->newLine();
        $this->components->info("Imported {$imported} schedule(s), {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Normalize a config entry to [cron, arguments].
     * Expects ['cron' => '...', 'arguments' => [...]].
     *
     * @return array{0: mixed, 1: array}
     */
    public static function parseEntry(array $value): array
    {
        return [$value['cron'] ?? '', $value['arguments'] ?? []];
    }

    /**
     * Validate that the number of provided arguments satisfies the constructor signature.
     * Returns an error string on failure, or null on success.
     */
    public static function validateArguments(string $class, array $arguments): ?string
    {
        $params = JobInspector::getConstructorParameters($class);

        if (empty($params) && empty($arguments)) {
            return null;
        }

        $required = array_values(array_filter($params, fn ($p) => ! $p->isOptional()));
        $provided = count($arguments);
        $requiredCount = count($required);
        $totalCount = count($params);

        if ($provided < $requiredCount) {
            return "Too few arguments: {$provided} provided, {$requiredCount} required.";
        }

        if ($provided > $totalCount) {
            return "Too many arguments: {$provided} provided, {$totalCount} accepted.";
        }

        return null;
    }

    private static function isValidCron(mixed $cron): bool
    {
        if (! is_string($cron) || trim($cron) === '') {
            return false;
        }

        try {
            new CronExpression($cron);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
