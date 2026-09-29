<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    'parent_item_id',
    'product_addon_id',
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
        'parent_item_id' => 'integer',
        'product_addon_id' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'parent_item_id');
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(ProductAddon::class, 'product_addon_id');
    }

    public function childAddons(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'parent_item_id');
    }

    public function isAddon(): bool
    {
        return $this->product_addon_id !== null;
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
