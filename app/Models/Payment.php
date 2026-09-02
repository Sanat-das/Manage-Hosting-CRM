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
     * method value => display label. Single source of truth for the method
     * filter/select dropdowns and rendered labels — must mirror the
     * payments.method enum (migration 2026_08_03_000002_widen_payments_method_enum.php)
     * or a value silently becomes unfilterable/unselectable.
     *
     * @var array<string, string>
     */
    public const METHOD_LABELS = [
        'razorpay' => 'Razorpay',
        'stripe' => 'Stripe',
        'paypal' => 'PayPal',
        'bank_transfer' => 'Bank Transfer',
        'cash' => 'Cash',
        'cheque' => 'Cheque',
        'wallet' => 'Wallet',
        'manual' => 'Manual',
        'credit' => 'Credit',
        'other' => 'Other',
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
