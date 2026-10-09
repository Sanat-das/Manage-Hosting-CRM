<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'to_email', 'cc_emails', 'bcc_emails', 'subject', 'template_name', 'status', 'body', 'error', 'log_key', 'from_email', 'attempts', 'payload'])]
class EmailLog extends Model
{
    protected $table = 'emails';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
