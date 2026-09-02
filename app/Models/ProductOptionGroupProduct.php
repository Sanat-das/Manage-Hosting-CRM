<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pivot row linking a product to an option group.
 *
 * Table `product_option_group_product` has created_at/updated_at.
 */
class ProductOptionGroupProduct extends Model
{
    protected $table = 'product_option_group_product';

    protected $fillable = [
        'product_id',
        'option_group_id',
        'customer_editable',
        'input_min',
        'input_max',
        'input_step',
        'input_placeholder',
        'sort_order',
    ];

    protected $casts = [
        'customer_editable' => 'boolean',
        'input_min' => 'decimal:2',
        'input_max' => 'decimal:2',
        'input_step' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public $timestamps = true;

    /**
     * Whether any per-product input override is set. Null fields inherit the
     * catalog group's value on the order form.
     */
    public function hasInputOverride(): bool
    {
        return $this->input_min !== null
            || $this->input_max !== null
            || $this->input_step !== null
            || $this->input_placeholder !== null;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'option_group_id');
    }

    public function linkValues(): HasMany
    {
        return $this->hasMany(ProductOptionLinkValue::class, 'product_option_group_product_id')->orderBy('sort_order');
    }

    /**
     * Per-billing-cycle unit price modifiers for continuous groups (slider /
     * number / quantity) attached to this product.
     */
    public function unitPricing(): HasMany
    {
        return $this->hasMany(ProductOptionLinkPricing::class, 'product_option_group_product_id');
    }
}
