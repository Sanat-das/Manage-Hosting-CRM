<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_instance_id', 'hosting_account_id', 'event_type', 'payload', 'event_status', 'status', 'priority', 'attempts', 'max_attempts', 'last_error', 'result', 'scheduled_at', 'locked_by', 'locked_at', 'completed_at', 'triggered_by'])]
class ProvisioningEvent extends Model
{
    protected $table = 'provisioning_events';

    /**
     * The table has `created_at` (plus its own `completed_at`) but no
     * `updated_at` — it is an append-only event log, not a mutable record.
     * Without this, every insert fails with "no column named updated_at",
     * which is why ServiceInstanceController's suspend/terminate event rows
     * never landed.
     */
    const UPDATED_AT = null;

    /**
     * A running Hyper-V build left behind by a killed worker.
     * $timeout is 1800s but on Windows without pcntl the failure callback
     * never fires, so the row would stay `running` forever without this.
     */
    public const RUNNING_STALE_AFTER_SECONDS = 2100;

    /**
     * True only when the row is still `running` and its `created_at` is
     * older than RUNNING_STALE_AFTER_SECONDS. No DB queries inside.
     */
    public function isStaleRunning(): bool
    {
        if ($this->status !== 'running') {
            return false;
        }

        if ($this->created_at === null) {
            return false;
        }

        try {
            return $this->created_at->diffInSeconds(now(), absolute: true) > self::RUNNING_STALE_AFTER_SECONDS;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'scheduled_at' => 'datetime',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'service_instance_id' => 'integer',
            'hosting_account_id' => 'integer',
            'triggered_by' => 'integer',
        ];
    }

    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class, 'service_instance_id');
    }

    public function hostingAccount(): BelongsTo
    {
        return $this->belongsTo(HostingAccount::class, 'hosting_account_id');
    }
}
