<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configurable option group shared by products through the
 * `product_option_group_product` pivot (EAV: groups → values → pricing).
 *
 * Table `product_option_groups` only has `created_at` (DB default), no
 * `updated_at`, so timestamps are disabled entirely.
 */
#[Fillable(['name', 'sort_order', 'type', 'input_min', 'input_max', 'input_step', 'input_placeholder'])]
class ProductOptionGroup extends Model
{
    /**
     * Option input types (product_option_groups.type enum).
     */
    public const OPTION_TYPES = ['dropdown', 'radio', 'quantity', 'text', 'number', 'slider', 'checkbox'];

    /**
     * Input types priced per unit: the customer picks a numeric value that
     * multiplies the product link's unit price (product_option_link_pricing).
     * The remaining types are discrete (dropdown / radio / checkbox, priced per
     * value) or free-form (text, unpriced).
     */
    public const CONTINUOUS_TYPES = ['slider', 'number', 'quantity'];

    public static function isContinuousType(?string $type): bool
    {
        return $type !== null && in_array($type, self::CONTINUOUS_TYPES, true);
    }

    public $timestamps = false;

    protected $casts = [
        'input_min' => 'decimal:2',
        'input_max' => 'decimal:2',
        'input_step' => 'decimal:2',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_option_group_product',
            'option_group_id',
            'product_id'
        )->withTimestamps();
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class, 'option_group_id');
    }
}
