<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot linking a product to a module. Each product carries its own
 * per-module `enabled` flag and `config` JSON.
 */
#[Fillable(['product_id', 'module_id', 'enabled', 'config'])]
class ProductModule extends Model
{
    protected $table = 'product_module';

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
