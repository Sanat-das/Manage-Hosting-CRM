<?php

namespace App\Models;

use App\Models\InventoryAsset;
use App\Models\LicenseAssignment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['inventory_asset_id', 'license_type', 'license_key', 'seats', 'seats_available', 'vendor', 'purchase_order', 'expiry_date', 'renewal_date', 'cost', 'status', 'notes'])]
class License extends Model
{
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'seats_available' => 'integer',
            'cost' => 'decimal:2',
            'expiry_date' => 'date',
            'renewal_date' => 'date',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InventoryAsset::class, 'inventory_asset_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LicenseAssignment::class);
    }
}
