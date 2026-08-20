<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['job_name', 'command', 'status', 'message', 'domains_processed', 'errors_count', 'started_at', 'completed_at', 'finished_at'])]
class CronLog extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'finished_at' => 'datetime',
            'domains_processed' => 'integer',
            'errors_count' => 'integer',
        ];
    }
}
