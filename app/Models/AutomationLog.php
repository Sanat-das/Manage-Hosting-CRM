<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['action', 'entity_type', 'entity_id', 'status', 'message', 'completed_at'])]
class AutomationLog extends Model
{
    protected $table = 'automation_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
