<?php

namespace CodeTechNL\TaskBridge\Support;

use Cron\CronExpression;
use InvalidArgumentException;

class CronTranslator
{
    /**
     * Convert a cron expression to AWS EventBridge Scheduler format.
     *
     * Accepts two input formats:
     *   - Standard 5-part:  minute hour dom month dow
     *   - AWS 6-part:       minute hour dom month dow year  (passed through as-is)
     *
     * Output: cron(minute hour dom month dow year)
     *
     * For 5-part input:
     *   - Either dom OR dow must be ?, not both *
     *   - dow is converted from standard (0=Sun) to EventBridge (1=Sun) numbering
     */
    public static function toEventBridge(string $expression): string
    {
        if (! self::isValid($expression)) {
            throw new InvalidArgumentException("Invalid cron expression: {$expression}");
        }

        $parts = explode(' ', trim($expression));

        // 6-part AWS format — already correct, just wrap it
        if (count($parts) === 6) {
            return "cron({$expression})";
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        // EventBridge requires exactly one of dom or dow to be ?
        if ($dayOfMonth === '*' && $dayOfWeek === '*') {
            $dayOfWeek = '?';
        } elseif ($dayOfMonth !== '*' && $dayOfWeek === '*') {
            $dayOfWeek = '?';
        } elseif ($dayOfMonth === '*' && $dayOfWeek !== '*') {
            $dayOfMonth = '?';
            $dayOfWeek = self::convertDayOfWeek($dayOfWeek);
        } else {
            // Both specified — prefer dom, ignore dow
            $dayOfWeek = '?';
        }

        return "cron({$minute} {$hour} {$dayOfMonth} {$month} {$dayOfWeek} *)";
    }

    /**
     * Validate a cron expression.
     *
     * Accepts:
     *   - Standard 5-part expressions (validated via dragonmantank/cron-expression)
     *   - AWS EventBridge 6-part expressions (minute hour dom month dow year)
     *     where exactly one of dom or dow is '?'
     */
    public static function isValid(string $expression): bool
    {
        $parts = explode(' ', trim($expression));

        if (count($parts) === 6) {
            return self::isValidEventBridgeExpression($parts);
        }

        try {
            new CronExpression($expression);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Validate a 6-part AWS EventBridge expression.
     * Rule: exactly one of dom (index 2) or dow (index 4) must be '?'.
     */
    private static function isValidEventBridgeExpression(array $parts): bool
    {
        $dom = $parts[2];
        $dow = $parts[4];

        // One must be '?', but not both
        return ($dom === '?' || $dow === '?') && ! ($dom === '?' && $dow === '?');
    }

    /**
     * Get a human-readable description of a cron expression.
     */
    public static function describe(string $expression): string
    {
        $parts = explode(' ', trim($expression));

        if (count($parts) !== 5) {
            return $expression;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        if ($minute === '*' && $hour === '*') {
            return 'Every minute';
        }

        if ($minute !== '*' && $hour === '*') {
            return 'Every hour at minute '.str_pad($minute, 2, '0', STR_PAD_LEFT);
        }

        if (str_contains($minute, ',') && $hour === '*') {
            return "Every hour at minutes {$minute}";
        }

        if ($minute === '0' && $hour === '*') {
            return 'Every hour';
        }

        if ($dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return 'Daily at '.str_pad($hour, 2, '0', STR_PAD_LEFT).':'.str_pad($minute, 2, '0', STR_PAD_LEFT);
        }

        return $expression;
    }

    /**
     * Calculate the next run time for a cron expression.
     * 6-part AWS expressions are trimmed to 5-part for dragonmantank compatibility.
     */
    public static function nextRunAt(string $expression): \DateTimeImmutable
    {
        $cron = new CronExpression(self::toFivePart($expression));

        return \DateTimeImmutable::createFromMutable($cron->getNextRunDate());
    }

    /**
     * Calculate the previous run time for a cron expression.
     * 6-part AWS expressions are trimmed to 5-part for dragonmantank compatibility.
     */
    public static function previousRunAt(string $expression): \DateTimeImmutable
    {
        $cron = new CronExpression(self::toFivePart($expression));

        return \DateTimeImmutable::createFromMutable($cron->getPreviousRunDate());
    }

    /**
     * Reduce a 6-part AWS expression to a 5-part standard expression for
     * dragonmantank/cron-expression compatibility (drops the year field).
     * 5-part expressions are returned unchanged.
     */
    private static function toFivePart(string $expression): string
    {
        $parts = explode(' ', trim($expression));

        if (count($parts) === 6) {
            // Replace '?' with '*' so dragonmantank can parse it
            return implode(' ', array_map(
                fn (string $p) => $p === '?' ? '*' : $p,
                array_slice($parts, 0, 5)
            ));
        }

        return $expression;
    }

    /**
     * Convert standard day-of-week (0-7, 0/7=Sunday) to EventBridge (1-7, 1=Sunday).
     */
    private static function convertDayOfWeek(string $dayOfWeek): string
    {
        // Handle comma-separated values
        if (str_contains($dayOfWeek, ',')) {
            return implode(',', array_map(
                fn ($d) => self::convertSingleDayOfWeek(trim($d)),
                explode(',', $dayOfWeek)
            ));
        }

        // Handle ranges
        if (str_contains($dayOfWeek, '-')) {
            [$start, $end] = explode('-', $dayOfWeek);

            return self::convertSingleDayOfWeek($start).'-'.self::convertSingleDayOfWeek($end);
        }

        return self::convertSingleDayOfWeek($dayOfWeek);
    }

    private static function convertSingleDayOfWeek(string $day): string
    {
        if (! is_numeric($day)) {
            return $day; // Named days like MON, TUE etc.
        }

        $num = (int) $day;

        // Standard: 0=Sunday, 7=Sunday → EventBridge: 1=Sunday
        if ($num === 0 || $num === 7) {
            return '1';
        }

        return (string) ($num + 1);
    }
}
