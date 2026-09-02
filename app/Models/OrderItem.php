<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'product_id',
    'product_name',
    'billing_cycle',
    'domain_name',
    'next_billing_date',
    'last_billing_date',
    'recurring_cycles_limit',
    'billing_cycles_count',
    'quantity',
    'unit_price',
    'total',
    'config_options',
])]
class OrderItem extends Model
{
    protected $casts = [
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'config_options' => 'array',
        'next_billing_date' => 'date',
        'last_billing_date' => 'date',
        'recurring_cycles_limit' => 'integer',
        'billing_cycles_count' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Snapshot of the selected option values captured at order time.
     *
     * Returns null when the column is null (no options were selected).
     */
    public function optionSnapshot(): ?array
    {
        return $this->config_options;
    }
}
