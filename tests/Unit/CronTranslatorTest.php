<?php

use CodeTechNL\TaskBridge\Support\CronTranslator;

describe('CronTranslator', function () {
    describe('toEventBridge', function () {
        it('converts a daily cron expression', function () {
            expect(CronTranslator::toEventBridge('0 3 * * *'))
                ->toBe('cron(0 3 * * ? *)');
        });

        it('converts an hourly cron expression', function () {
            expect(CronTranslator::toEventBridge('0 * * * *'))
                ->toBe('cron(0 * * * ? *)');
        });

        it('converts every-minute cron expression', function () {
            expect(CronTranslator::toEventBridge('* * * * *'))
                ->toBe('cron(* * * * ? *)');
        });

        it('converts a monthly cron expression', function () {
            expect(CronTranslator::toEventBridge('0 3 1 * *'))
                ->toBe('cron(0 3 1 * ? *)');
        });

        it('converts a cron with specific day-of-week', function () {
            expect(CronTranslator::toEventBridge('0 9 * * 1'))
                ->toBe('cron(0 9 ? * 2 *)'); // Monday in EventBridge = 2
        });

        it('converts a cron with comma-separated minutes', function () {
            expect(CronTranslator::toEventBridge('0,30 * * * *'))
                ->toBe('cron(0,30 * * * ? *)');
        });

        it('passes through a valid 6-part AWS expression unchanged', function () {
            expect(CronTranslator::toEventBridge('* * * * ? *'))
                ->toBe('cron(* * * * ? *)');
            expect(CronTranslator::toEventBridge('0 3 * * ? *'))
                ->toBe('cron(0 3 * * ? *)');
            expect(CronTranslator::toEventBridge('0 3 ? * 2 *'))
                ->toBe('cron(0 3 ? * 2 *)');
        });

        it('throws on invalid expression', function () {
            expect(fn () => CronTranslator::toEventBridge('not-a-cron'))
                ->toThrow(InvalidArgumentException::class);
        });

        it('throws on wrong number of parts', function () {
            expect(fn () => CronTranslator::toEventBridge('0 3 * *'))
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('isValid', function () {
        it('returns true for valid 5-part expressions', function () {
            expect(CronTranslator::isValid('0 3 * * *'))->toBeTrue();
            expect(CronTranslator::isValid('* * * * *'))->toBeTrue();
            expect(CronTranslator::isValid('0,30 * * * *'))->toBeTrue();
            expect(CronTranslator::isValid('0 9 * * 1'))->toBeTrue();
            expect(CronTranslator::isValid('0 0 1 * *'))->toBeTrue();
            expect(CronTranslator::isValid('*/5 * * * *'))->toBeTrue();
        });

        it('returns true for valid 6-part AWS expressions', function () {
            expect(CronTranslator::isValid('* * * * ? *'))->toBeTrue();
            expect(CronTranslator::isValid('0 3 * * ? *'))->toBeTrue();
            expect(CronTranslator::isValid('0 3 ? * 2 *'))->toBeTrue();
            expect(CronTranslator::isValid('0 0 1 * ? *'))->toBeTrue();
        });

        it('returns false for invalid expressions', function () {
            expect(CronTranslator::isValid('not-a-cron'))->toBeFalse();
            expect(CronTranslator::isValid('99 * * * *'))->toBeFalse();
            expect(CronTranslator::isValid('* 25 * * *'))->toBeFalse();
            expect(CronTranslator::isValid('0 3 * *'))->toBeFalse();
            expect(CronTranslator::isValid(''))->toBeFalse();
        });

        it('returns false for 6-part expressions where neither dom nor dow is ?', function () {
            expect(CronTranslator::isValid('0 3 * * * *'))->toBeFalse();
        });

        it('returns false for 6-part expressions where both dom and dow are ?', function () {
            expect(CronTranslator::isValid('0 3 ? * ? *'))->toBeFalse();
        });
    });

    describe('form validation rule behavior', function () {
        it('accepts a valid cron expression', function () {
            $failed = false;
            $rule = function (string $attribute, mixed $value, Closure $fail) use (&$failed) {
                if ($value && ! CronTranslator::isValid($value)) {
                    $failed = true;
                    $fail('Invalid cron expression.');
                }
            };
            $rule('cron_override', '0 3 * * *', function () use (&$failed) {
                $failed = true;
            });
            expect($failed)->toBeFalse();
        });

        it('rejects an invalid cron expression', function () {
            $message = null;
            $rule = function (string $attribute, mixed $value, Closure $fail) use (&$message) {
                if ($value && ! CronTranslator::isValid($value)) {
                    $fail('Invalid cron expression.');
                }
            };
            $rule('cron_override', 'not-a-cron', function (string $msg) use (&$message) {
                $message = $msg;
            });
            expect($message)->toBe('Invalid cron expression.');
        });

        it('does not reject an empty value (required handled separately)', function () {
            $message = null;
            $rule = function (string $attribute, mixed $value, Closure $fail) use (&$message) {
                if ($value && ! CronTranslator::isValid($value)) {
                    $fail('Invalid cron expression.');
                }
            };
            $rule('cron_override', '', function (string $msg) use (&$message) {
                $message = $msg;
            });
            expect($message)->toBeNull();
        });
    });

    describe('retry policy validation', function () {
        it('accepts valid event age values', function () {
            foreach ([60, 3600, 86400] as $value) {
                $failed = false;
                $rule = function (string $attribute, mixed $val, Closure $fail) use (&$failed) {
                    if ($val !== null && ($val < 60 || $val > 86400)) {
                        $failed = true;
                        $fail('Out of range.');
                    }
                };
                $rule('retry_maximum_event_age_seconds', $value, function () use (&$failed) {
                    $failed = true;
                });
                expect($failed)->toBeFalse("Expected {$value} to be valid");
            }
        });

        it('rejects event age below 60', function () {
            $message = null;
            $rule = function (string $attribute, mixed $value, Closure $fail) use (&$message) {
                if ($value !== null && ($value < 60 || $value > 86400)) {
                    $fail('Max event age must be between 60 and 86 400 seconds.');
                }
            };
            $rule('retry_maximum_event_age_seconds', 59, function (string $msg) use (&$message) {
                $message = $msg;
            });
            expect($message)->not->toBeNull();
        });

        it('rejects event age above 86400', function () {
            $message = null;
            $rule = function (string $attribute, mixed $value, Closure $fail) use (&$message) {
                if ($value !== null && ($value < 60 || $value > 86400)) {
                    $fail('Max event age must be between 60 and 86 400 seconds.');
                }
            };
            $rule('retry_maximum_event_age_seconds', 86401, function (string $msg) use (&$message) {
                $message = $msg;
            });
            expect($message)->not->toBeNull();
        });

        it('accepts valid retry attempt values', function () {
            foreach ([0, 1, 100, 185] as $value) {
                $failed = false;
                $rule = function (string $attribute, mixed $val, Closure $fail) use (&$failed) {
                    if ($val !== null && ($val < 0 || $val > 185)) {
                        $failed = true;
                        $fail('Out of range.');
                    }
                };
                $rule('retry_maximum_retry_attempts', $value, function () use (&$failed) {
                    $failed = true;
                });
                expect($failed)->toBeFalse("Expected {$value} to be valid");
            }
        });

        it('rejects retry attempts above 185', function () {
            $message = null;
            $rule = function (string $attribute, mixed $value, Closure $fail) use (&$message) {
                if ($value !== null && ($value < 0 || $value > 185)) {
                    $fail('Max retry attempts must be between 0 and 185.');
                }
            };
            $rule('retry_maximum_retry_attempts', 186, function (string $msg) use (&$message) {
                $message = $msg;
            });
            expect($message)->not->toBeNull();
        });

        it('allows null values (falls back to config)', function () {
            $message = null;
            $ageRule = function (string $attribute, mixed $value, Closure $fail) use (&$message) {
                if ($value !== null && ($value < 60 || $value > 86400)) {
                    $fail('Out of range.');
                }
            };
            $ageRule('retry_maximum_event_age_seconds', null, function (string $msg) use (&$message) {
                $message = $msg;
            });
            expect($message)->toBeNull();

            $retriesRule = function (string $attribute, mixed $value, Closure $fail) use (&$message) {
                if ($value !== null && ($value < 0 || $value > 185)) {
                    $fail('Out of range.');
                }
            };
            $retriesRule('retry_maximum_retry_attempts', null, function (string $msg) use (&$message) {
                $message = $msg;
            });
            expect($message)->toBeNull();
        });
    });

    describe('nextRunAt', function () {
        it('returns a DateTimeImmutable in the future', function () {
            $next = CronTranslator::nextRunAt('* * * * *');
            expect($next)->toBeInstanceOf(DateTimeImmutable::class);
            expect($next->getTimestamp())->toBeGreaterThan(time());
        });
    });
});
