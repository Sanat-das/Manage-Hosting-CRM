<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['service_id', 'resource_type_id', 'pool_id', 'inventory_asset_id', 'quantity_allocated', 'allocated_at', 'released_at', 'status'])]
class ResourceAllocation extends Model
{
    protected $table = 'resource_allocations';

    /**
     * The table records its own `allocated_at` / `released_at` moments; it
     * has no created_at / updated_at columns.
     */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity_allocated' => 'decimal:4',
            'allocated_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
