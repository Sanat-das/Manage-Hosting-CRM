<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operator-settable state for one scheduled task, keyed by the stable task
 * key derived in App\Services\Cron\CronTaskRegistry.
 *
 * A task with NO row here is enabled — the table records only deviations
 * from what routes/console.php declares, so a newly added schedule starts
 * running without any data change.
 */
#[Fillable(['key', 'enabled', 'updated_by', 'expression', 'timezone', 'description'])]
class CronTask extends Model
{
    protected $table = 'cron_tasks';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(CronTaskRun::class, 'task_key', 'key');
    }
}
