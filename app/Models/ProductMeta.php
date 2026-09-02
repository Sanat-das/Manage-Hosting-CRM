<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Key/value custom metadata attached to a product.
 */
#[Fillable(['product_id', 'meta_key', 'meta_value'])]
class ProductMeta extends Model
{
    protected $table = 'product_meta';
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
