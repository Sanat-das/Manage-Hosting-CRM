<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A concrete value offered for a linked option group on a product.
 *
 * Table `product_option_link_values` has no timestamps at all.
 */
class ProductOptionLinkValue extends Model
{
    protected $fillable = ['product_option_group_product_id', 'label', 'is_default', 'sort_order'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public $timestamps = false;

    public function link(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroupProduct::class, 'product_option_group_product_id');
    }

    public function pricing(): HasMany
    {
        return $this->hasMany(ProductOptionLinkValuePricing::class);
    }
}
