<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['to_email', 'subject', 'body', 'status', 'attempts', 'error', 'sent_at'])]
class EmailQueue extends Model
{
    protected $table = 'email_queue';

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
