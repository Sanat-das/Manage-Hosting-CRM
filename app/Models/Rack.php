<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['datacenter_id', 'name', 'u_height', 'u_available', 'power_capacity_watts', 'status'])]
class Rack extends Model
{
    protected function casts(): array
    {
        return [
            'u_height' => 'integer',
            'u_available' => 'integer',
            'power_capacity_watts' => 'integer',
        ];
    }

    public function datacenter(): BelongsTo
    {
        return $this->belongsTo(Datacenter::class);
    }

    public function inventoryAssets(): HasMany
    {
        return $this->hasMany(InventoryAsset::class);
    }
}
