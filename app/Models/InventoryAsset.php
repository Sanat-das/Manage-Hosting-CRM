<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['asset_tag', 'serial_number', 'asset_type', 'manufacturer', 'model', 'vendor', 'purchase_date', 'purchase_cost', 'warranty_expiry', 'datacenter_id', 'rack_id', 'rack_u_position', 'parent_asset_id', 'status', 'notes'])]
class InventoryAsset extends Model
{
    use SoftDeletes;

    /**
     * Selectable values of the inventory_assets.asset_type enum — the single
     * source of truth for the controller validation and the admin form/filter
     * dropdowns. IP addresses are intentionally excluded: IPs are managed in
     * IP Manager (ip_addresses) and assigned from there, never created as
     * inventory assets. The DB enum keeps the legacy ipv4_address /
     * ipv6_address values for rows imported before this change.
     */
    public const ASSET_TYPES = [
        'server', 'ram_module', 'cpu', 'ssd', 'hdd', 'gpu', 'raid_controller',
        'nic', 'switch', 'pdu', 'other_hardware', 'software_license',
        'ssl_certificate', 'domain',
    ];

    /**
     * Valid values of the inventory_assets.status enum.
     */
    public const STATUSES = [
        'ordered', 'received', 'in_stock', 'installed', 'assigned',
        'maintenance', 'retired', 'disposed',
    ];

    /**
     * Asset types whose create/edit forms show the IP Manager picker. Only a
     * server or switch links IP addresses (via ip_addresses.inventory_asset_id);
     * every other type hides the picker.
     */
    public const IP_TRACKING_TYPES = ['server', 'switch'];

    /**
     * Asset types whose show page renders the ports card, mirroring the
     * IP_TRACKING_TYPES visibility rule.
     */
    public const PORT_BEARING_TYPES = ['server', 'switch', 'nic', 'pdu', 'other_hardware'];

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

    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class, 'inventory_asset_id');
    }

    public function ports(): HasMany
    {
        return $this->hasMany(DevicePort::class, 'inventory_asset_id');
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
