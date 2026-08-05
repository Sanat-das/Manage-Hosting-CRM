<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'billing_cycle', 'setup_fee', 'price', 'promo_price', 'promo_start', 'promo_end'])]
class ProductPricing extends Model
{
    protected $casts = [
        'setup_fee' => 'decimal:2',
        'price' => 'decimal:2',
        'promo_price' => 'decimal:2',
        'promo_start' => 'date',
        'promo_end' => 'date',
    ];
    protected $table = 'product_pricing';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
