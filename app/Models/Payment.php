<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'amount', 'method', 'gateway_id', 'transaction_id', 'status', 'notes'])]
class Payment extends Model
{
    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * The payments table (schema.sql L226, migration 120040) has only a
     * created_at column (useCurrent) — no updated_at. Keep created_at
     * auto-filling while disabling the missing updated_at writes.
     */
    public const UPDATED_AT = null;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
