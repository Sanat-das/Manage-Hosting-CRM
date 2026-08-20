<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
