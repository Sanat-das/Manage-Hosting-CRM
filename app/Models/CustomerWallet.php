<?php

namespace App\Models;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'type', 'amount', 'balance_type', 'description', 'admin_user_id', 'invoice_id'])]
class CustomerWallet extends Model
{
    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * The wallet ledger table (singular, per the reference schema).
     */
    protected $table = 'customer_wallet';

    /**
     * The wallet ledger has no updated_at column in the schema.
     */
    public const UPDATED_AT = null;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
