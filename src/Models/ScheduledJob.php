<?php

namespace CodeTechNL\TaskBridge\Models;

use Carbon\Carbon;
use CodeTechNL\TaskBridge\Enums\RunStatus;
use CodeTechNL\TaskBridge\Support\ScheduledJobCollection;
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
     */
    public static function identifierFromClass(string $class): string
    {
        $identifier = Str::kebab(class_basename($class));
        $prefix = config('taskbridge.name_prefix');

        return $prefix ? Str::kebab($prefix).'-'.$identifier : $identifier;
    }
}
