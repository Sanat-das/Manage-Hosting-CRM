<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['device_id', 'parent_item_id', 'name', 'label', 'sku', 'manufacturer_id', 'part_number', 'serial_number', 'asset_tag', 'status', 'discovered', 'description', 'notes'])]
class InventoryItem extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Valid values of the inventory_items.status enum.
     */
    public const STATUSES = Device::STATUSES;

    protected function casts(): array
    {
        return [
            'discovered' => 'boolean',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_item_id');
    }
}
