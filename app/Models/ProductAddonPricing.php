<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-cycle price for a product add-on (WHMCS pricing matrix).
 *
 * A row here overrides the add-on's base cycle/price/setup_fee when the
 * add-on is bought together with a parent product billed on that cycle.
 */
#[Fillable(['product_addon_id', 'billing_cycle', 'price', 'setup_fee'])]
class ProductAddonPricing extends Model
{
    protected $casts = [
        'setup_fee' => 'decimal:2',
        'price' => 'decimal:2',
    ];

    protected $table = 'product_addon_pricing';

    public function productAddon(): BelongsTo
    {
        return $this->belongsTo(ProductAddon::class);
    }
}
