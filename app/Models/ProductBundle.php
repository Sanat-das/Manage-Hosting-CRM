<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A component line of a bundle product. Each row ties one component product
 * (with its own ProductPricing ladder) into a bundle product, with a per-row
 * quantity and discount. The bundle's price is derived, never stored: see
 * App\Services\ProductBundlePricingService.
 */
#[Fillable(['bundle_product_id', 'component_product_id', 'quantity', 'discount_type', 'discount_value', 'sort_order'])]
class ProductBundle extends Model
{
    use HasFactory;

    protected $casts = [
        'quantity' => 'integer',
        'discount_value' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_product_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
