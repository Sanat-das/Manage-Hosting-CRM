<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-billing-cycle unit price modifier for a linked continuous option group
 * (slider / number / quantity) on a product. The customer's chosen value is
 * multiplied by this unit price at checkout.
 *
 * Table `product_option_link_pricing` has no timestamps at all.
 */
class ProductOptionLinkPricing extends Model
{
    protected $table = 'product_option_link_pricing';

    protected $fillable = ['product_option_group_product_id', 'billing_cycle', 'price_modifier'];

    protected $casts = [
        'price_modifier' => 'decimal:2',
    ];

    public $timestamps = false;

    public function link(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroupProduct::class, 'product_option_group_product_id');
    }
}
