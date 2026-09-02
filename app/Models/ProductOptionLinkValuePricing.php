<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-billing-cycle price modifier for a link value.
 *
 * Table `product_option_link_value_pricing` has no timestamps at all.
 */
class ProductOptionLinkValuePricing extends Model
{
    protected $table = 'product_option_link_value_pricing';

    protected $fillable = ['product_option_link_value_id', 'billing_cycle', 'price_modifier'];

    protected $casts = [
        'price_modifier' => 'decimal:2',
    ];

    public $timestamps = false;

    public function value(): BelongsTo
    {
        return $this->belongsTo(ProductOptionLinkValue::class, 'product_option_link_value_id');
    }
}
