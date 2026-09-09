<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Configurable option group shared by products through the
 * `product_option_group_product` pivot (EAV: groups → values → pricing).
 *
 * A group is one product feature — RAM, CPU, Storage, Backup, Support. `name`
 * is what a customer reads, `key` is what code acts on (the provisioning
 * handle) and `unit` is how a bare number is rendered ("200 GB").
 *
 * Table `product_option_groups` only has `created_at` (DB default), no
 * `updated_at`, so timestamps are disabled entirely.
 */
#[Fillable(['name', 'key', 'unit', 'sort_order', 'type', 'input_min', 'input_max', 'input_step', 'input_placeholder'])]
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

    /**
     * Groups self-key from their name when none is given, so a group created
     * through the admin form (which has no key field) is still addressable by
     * a provisioning module. The column is unique, hence the numeric suffix on
     * a collision — two groups both named "Storage" become storage and
     * storage-2.
     */
    protected static function booted(): void
    {
        static::creating(function (self $group) {
            if (empty($group->getAttributes()['key'] ?? null)) {
                $group->key = self::generateKey((string) $group->name);
            }
        });
    }

    public static function generateKey(string $name): string
    {
        $base = Str::slug($name) ?: 'option';
        $candidate = $base;
        $suffix = 1;

        while (self::where('key', $candidate)->exists()) {
            $candidate = $base.'-'.++$suffix;
        }

        return $candidate;
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_option_group_product',
            'option_group_id',
            'product_id'
        )->withTimestamps();
    }

    /**
     * The pivot rows attaching this group to products — the link layer that
     * actually carries the per-product values and prices. Use this rather than
     * `products()` whenever the link (not just the product) matters: attaching
     * and detaching must go through ProductOptionLinkService, because a bare
     * pivot row has no values and a deleted one cascades its pricing away.
     */
    public function productLinks(): HasMany
    {
        return $this->hasMany(ProductOptionGroupProduct::class, 'option_group_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class, 'option_group_id');
    }
}
