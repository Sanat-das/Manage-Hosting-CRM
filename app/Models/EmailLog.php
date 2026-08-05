<?php

namespace App\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'to_email', 'subject', 'template_name', 'status', 'body', 'error'])]
class EmailLog extends Model
{
    protected $table = 'emails';

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
