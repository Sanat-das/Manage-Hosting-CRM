<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-billing-cycle price modifier for a configurable option value.
 *
 * Table `product_option_pricing` has no timestamps at all.
 */
#[Fillable(['option_value_id', 'billing_cycle', 'price_modifier'])]
class ProductOptionPricing extends Model
{
    protected $table = 'product_option_pricing';

    protected $casts = [
        'price_modifier' => 'decimal:2',
    ];

    public $timestamps = false;

    public function optionValue(): BelongsTo
    {
        return $this->belongsTo(ProductOptionValue::class, 'option_value_id');
    }
}
