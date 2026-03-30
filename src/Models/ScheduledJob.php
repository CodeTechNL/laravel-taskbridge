<?php

namespace CodeTechNL\TaskBridge\Models;

use Carbon\Carbon;
use CodeTechNL\TaskBridge\Enums\RunStatus;
use CodeTechNL\TaskBridge\Support\ScheduledJobCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $class
 * @property string $identifier
 * @property string|null $group
 * @property string|null $description
 * @property string|null $cron_expression
 * @property string|null $cron_override
 * @property array|null $constructor_arguments
 * @property Carbon|null $run_once_at
 * @property string|null $run_once_schedule_name
 * @property int|null $retry_maximum_event_age_seconds
 * @property int|null $retry_maximum_retry_attempts
 * @property bool $enabled
 * @property Carbon|null $last_run_at
 * @property RunStatus|null $last_status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string|null $effective_cron
 */
class ScheduledJob extends Model
{
    use HasUlids;

    protected $table = 'taskbridge_jobs';

    protected $fillable = [
        'class',
        'identifier',
        'queue_connection',
        'group',
        'description',
        'cron_expression',
        'cron_override',
        'constructor_arguments',
        'run_once_at',
        'run_once_schedule_name',
        'retry_maximum_event_age_seconds',
        'retry_maximum_retry_attempts',
        'enabled',
        'last_run_at',
        'last_status',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_run_at' => 'datetime',
        'last_status' => RunStatus::class,
        'constructor_arguments' => 'array',
        'run_once_at' => 'datetime',
        'retry_maximum_event_age_seconds' => 'integer',
        'retry_maximum_retry_attempts' => 'integer',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(
            config('taskbridge.models.scheduled_job_run', ScheduledJobRun::class),
            'scheduled_job_id'
        );
    }

    public function isOnce(): bool
    {
        return $this->run_once_at !== null;
    }

    public function scopeRecurring(Builder $query): Builder
    {
        return $query->whereNull('run_once_at');
    }

    public function getEffectiveCronAttribute(): ?string
    {
        return $this->cron_override ?? $this->cron_expression;
    }

    public function newCollection(array $models = []): ScheduledJobCollection
    {
        return new ScheduledJobCollection($models);
    }

    /**
     * Derive a stable identifier from a fully-qualified class name.
     * E.g. App\Jobs\SendTrialExpiredNotifications → send-trial-expired-notifications
     *
     * When taskbridge.name_prefix is set, the identifier is prefixed:
     * E.g. prefix "acme" → acme-send-trial-expired-notifications
     *
     * AWS EventBridge Scheduler limits schedule names to 64 characters. The identifier
     * IS the schedule name, so it must not exceed 64 characters.
     *
     * When the identifier exceeds that budget, the bare class-name part (without
     * name_prefix) is replaced with its MD5 hash. The name_prefix is NOT included
     * in the hash so it stays human-readable and the result is deterministic.
     *
     * If even name_prefix + "-" + MD5 exceeds the budget, a RuntimeException is
     * thrown — the name_prefix itself must be shortened.
     *
     * @throws \RuntimeException when the name_prefix is so long that even the
     *                           MD5-based identifier exceeds the 64-character budget.
     */
    public static function identifierFromClass(string $class): string
    {
        $bare = Str::kebab(class_basename($class));
        $sluggedPrefix = ($prefix = config('taskbridge.name_prefix'))
            ? Str::kebab($prefix)
            : null;

        // AWS EventBridge Scheduler limits schedule names to 64 characters.
        // The identifier IS the schedule name, so the budget is the full 64 chars.
        $maxLength = 64;

        $identifier = $sluggedPrefix ? "{$sluggedPrefix}-{$bare}" : $bare;

        if (strlen($identifier) <= $maxLength) {
            return $identifier;
        }

        // Identifier exceeds the budget — replace the bare class-name part with
        // its MD5 hash. The name_prefix is NOT included in the hash so it stays
        // human-readable and the result remains deterministic.
        $hashed = md5($bare);
        $hashedIdentifier = $sluggedPrefix ? "{$sluggedPrefix}-{$hashed}" : $hashed;

        if (strlen($hashedIdentifier) > $maxLength) {
            $maxPrefixLength = $maxLength - 1 - 32; // budget − dash − 32 hex chars

            throw new \RuntimeException(
                "TaskBridge: the identifier for \"{$class}\" exceeds the 64-character AWS EventBridge Scheduler limit "
                .'even after MD5 hashing. '
                ."The name_prefix \"{$prefix}\" is too long — shorten it to at most {$maxPrefixLength} characters "
                .'(TASKBRIDGE_NAME_PREFIX).'
            );
        }

        return $hashedIdentifier;
    }
}
