<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_instance_id', 'event_type', 'payload', 'event_status', 'status', 'priority', 'attempts', 'max_attempts', 'last_error', 'result', 'scheduled_at', 'locked_by', 'locked_at', 'completed_at', 'triggered_by'])]
class ProvisioningEvent extends Model
{
    protected $table = 'provisioning_events';

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
            'triggered_by' => 'integer',
        ];
    }

    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class, 'service_instance_id');
    }
}
