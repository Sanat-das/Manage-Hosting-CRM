<?php

namespace App\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'quote_no', 'subject', 'stage', 'subtotal', 'discount', 'tax', 'total', 'notes', 'valid_until'])]
class Quote extends Model
{
    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'valid_until' => 'date',
    ];
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
