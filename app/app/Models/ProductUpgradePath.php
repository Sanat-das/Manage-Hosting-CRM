<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed product upgrade path (from -> to). Configuration only — the
 * billing engine that prices the upgrade is out of scope (T4.4).
 */
#[Fillable(['from_product_id', 'to_product_id', 'enabled'])]
class ProductUpgradePath extends Model
{
    use HasFactory;

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function fromProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'from_product_id');
    }

    public function toProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }
}
