<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Product add-on (attached to one product, or global when product_id is null).
 */
#[Fillable(['product_id', 'name', 'description', 'billing_cycle', 'setup_fee', 'price', 'welcome_email_template_id', 'status'])]
class ProductAddon extends Model
{
    protected $casts = [
        'setup_fee' => 'decimal:2',
        'price' => 'decimal:2',
        'status' => 'string',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_addon_id');
    }

    /** Per-cycle price matrix; a row overrides the base price for that cycle. */
    public function pricing(): HasMany
    {
        return $this->hasMany(ProductAddonPricing::class)->orderBy('id');
    }

    /** Matrix row for a billing cycle, or null when the cycle is not priced. */
    public function priceForCycle(string $cycle): ?ProductAddonPricing
    {
        return $this->pricing()->where('billing_cycle', $cycle)->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeApplicableTo(Builder $query, int $productId): Builder
    {
        return $query->active()->where(function (Builder $q) use ($productId) {
            $q->whereNull('product_id')->orWhere('product_id', $productId);
        });
    }
}
