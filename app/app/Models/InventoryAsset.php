<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['asset_tag', 'serial_number', 'asset_type', 'manufacturer', 'model', 'vendor', 'purchase_date', 'purchase_cost', 'warranty_expiry', 'datacenter_id', 'rack_id', 'rack_u_position', 'parent_asset_id', 'status', 'lifecycle_state', 'notes'])]
class InventoryAsset extends Model
{
    use SoftDeletes;

    /**
     * Valid values of the inventory_assets.asset_type enum — the single source
     * of truth for the controller validation and the admin form/filter
     * dropdowns (mirrors the migration enum).
     */
    public const ASSET_TYPES = [
        'server', 'ram_module', 'cpu', 'ssd', 'hdd', 'gpu', 'raid_controller',
        'nic', 'switch', 'pdu', 'other_hardware', 'software_license',
        'ipv4_address', 'ipv6_address', 'ssl_certificate', 'domain',
    ];

    /**
     * Valid values of the inventory_assets.status and lifecycle_state enums.
     */
    public const STATUSES = [
        'ordered', 'received', 'in_stock', 'installed', 'assigned',
        'maintenance', 'retired', 'disposed',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_cost' => 'decimal:2',
            'warranty_expiry' => 'date',
            'rack_u_position' => 'integer',
        ];
    }

    public function datacenter(): BelongsTo
    {
        return $this->belongsTo(Datacenter::class);
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_asset_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_asset_id');
    }
}
