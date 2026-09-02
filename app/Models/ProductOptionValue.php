<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single selectable value inside a configurable option group.
 *
 * Table `product_option_values` has no timestamps at all.
 */
#[Fillable(['option_group_id', 'label', 'sort_order'])]
class ProductOptionValue extends Model
{
    public $timestamps = false;

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'option_group_id');
    }

    public function pricing(): HasMany
    {
        return $this->hasMany(ProductOptionPricing::class, 'option_value_id');
    }
}
