<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['asset_tag', 'serial_number', 'device_type_id', 'manufacturer', 'model', 'vendor', 'purchase_date', 'purchase_cost', 'warranty_expiry', 'datacenter_id', 'rack_id', 'rack_u_position', 'face', 'status', 'notes'])]
class Device extends Model
{
    use SoftDeletes;

    /**
     * Valid values of the devices.status enum.
     */
    public const STATUSES = [
        'ordered', 'received', 'in_stock', 'installed', 'assigned',
        'maintenance', 'retired', 'disposed',
    ];

    /**
     * Device type categories whose create/edit forms show the IP Manager
     * picker. Only a server or switch links IP addresses (via
     * ip_addresses.device_id); every other category hides the picker.
     * Compared against the linked device type's `category`.
     */
    public const IP_TRACKING_TYPES = ['server', 'switch'];

    /**
     * Device type categories whose show page renders the ports card,
     * mirroring the IP_TRACKING_TYPES visibility rule.
     */
    public const PORT_BEARING_TYPES = ['server', 'switch', 'pdu', 'other_hardware'];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_cost' => 'decimal:2',
            'warranty_expiry' => 'date',
            'rack_u_position' => 'integer',
            'face' => 'string',
        ];
    }

    public function datacenter(): BelongsTo
    {
        return $this->belongsTo(Datacenter::class);
    }

    public function deviceType(): BelongsTo
    {
        return $this->belongsTo(DeviceType::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class, 'device_id');
    }

    public function ports(): HasMany
    {
        return $this->hasMany(DevicePort::class, 'device_id');
    }
}
