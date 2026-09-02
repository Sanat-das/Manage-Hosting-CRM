<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded execution of a scheduled task.
 *
 * Rows open as STATUS_RUNNING when the task starts and are closed by the
 * matching finish/failure event. A row left RUNNING long after started_at
 * means the process died mid-task (or, for a background task, that
 * `schedule:finish` never ran) — the page surfaces that rather than hiding it.
 */
#[Fillable([
    'task_key', 'status', 'trigger', 'started_at', 'finished_at',
    'runtime_ms', 'exit_code', 'message', 'triggered_by',
])]
class CronTaskRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_MANUAL = 'manual';

    protected $table = 'cron_task_runs';

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'runtime_ms' => 'integer',
        'exit_code' => 'integer',
    ];

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CronTask::class, 'task_key', 'key');
    }
}
