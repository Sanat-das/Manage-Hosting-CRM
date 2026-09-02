<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'resource_type_id', 'quantity', 'is_required', 'is_upgradable', 'min_quantity', 'max_quantity'])]
class ProductResource extends Model
{
    protected $table = 'product_resources';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'is_required' => 'boolean',
            'is_upgradable' => 'boolean',
            'min_quantity' => 'decimal:4',
            'max_quantity' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class, 'resource_type_id');
    }
}
