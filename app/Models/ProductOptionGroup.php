<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configurable option group for a product (EAV: groups → values → pricing).
 *
 * Table `product_option_groups` only has `created_at` (DB default), no
 * `updated_at`, so timestamps are disabled entirely.
 */
#[Fillable(['product_id', 'name', 'sort_order', 'type'])]
class ProductOptionGroup extends Model
{
    public $timestamps = false;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class, 'option_group_id');
    }
}
