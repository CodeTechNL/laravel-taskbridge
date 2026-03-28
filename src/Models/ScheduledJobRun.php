<?php

namespace CodeTechNL\TaskBridge\Models;

use Carbon\Carbon;
use CodeTechNL\TaskBridge\Enums\RunStatus;
use CodeTechNL\TaskBridge\Enums\TriggeredBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $scheduled_job_id
 * @property RunStatus $status
 * @property string $triggered_by
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property int $jobs_dispatched
 * @property string|null $skipped_reason
 * @property Carbon $created_at
 */
class ScheduledJobRun extends Model
{
    use HasUlids;
    use Prunable;

    public $timestamps = false;

    protected $table = 'taskbridge_job_runs';

    protected $fillable = [
        'scheduled_job_id',
        'status',
        'triggered_by',
        'started_at',
        'finished_at',
        'duration_ms',
        'jobs_dispatched',
        'skipped_reason',
        'output',
        'created_at',
    ];

    protected $casts = [
        'status' => RunStatus::class,
        'triggered_by' => TriggeredBy::class,
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'jobs_dispatched' => 'integer',
        'duration_ms' => 'integer',
        'output' => 'array',
    ];

    public function scheduledJob(): BelongsTo
    {
        return $this->belongsTo(
            config('taskbridge.models.scheduled_job', ScheduledJob::class),
            'scheduled_job_id'
        );
    }

    public function prunable(): Builder
    {
        $days = config('taskbridge.logging.retention_days', 30);

        return static::where('created_at', '<', now()->subDays($days));
    }
}
