<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'to_email', 'cc_emails', 'bcc_emails', 'subject', 'template_name', 'status', 'body', 'error'])]
class EmailLog extends Model
{
    protected $table = 'emails';

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
