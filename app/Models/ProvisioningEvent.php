<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_type', 'payload', 'status', 'priority', 'attempts', 'max_attempts', 'last_error', 'scheduled_at', 'locked_by', 'locked_at', 'completed_at'])]
class ProvisioningEvent extends Model
{
    protected $table = 'provisioning_events';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'scheduled_at' => 'datetime',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }
}
