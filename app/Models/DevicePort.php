<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['inventory_asset_id', 'name', 'port_number', 'port_type', 'media', 'speed_bps', 'status', 'mac_address', 'source', 'snmp_if_index', 'snmp_if_descr', 'last_synced_at', 'notes'])]
class DevicePort extends Model
{
    /**
     * Selectable values of device_ports.port_type — a string column rather than
     * a DB enum because SNMP ifType is an open set.
     */
    public const PORT_TYPES = [
        'ethernet', 'sfp', 'sfp_plus', 'qsfp', 'fiber', 'usb', 'console',
        'power', 'lag', 'vlan', 'loopback', 'virtual', 'other',
    ];

    /**
     * Valid values of device_ports.media.
     */
    public const MEDIA = ['copper', 'fiber', 'virtual', 'other'];

    /**
     * Valid values of device_ports.status.
     */
    public const STATUSES = ['up', 'down', 'disabled', 'unknown'];

    /**
     * Valid values of device_ports.source — how the row was created.
     */
    public const SOURCES = ['manual', 'snmp'];

    protected $table = 'device_ports';

    protected $attributes = [
        'port_type' => 'other',
        'status' => 'unknown',
        'source' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'speed_bps' => 'integer',
            'snmp_if_index' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function inventoryAsset(): BelongsTo
    {
        return $this->belongsTo(InventoryAsset::class, 'inventory_asset_id');
    }

    public function connectionAsA(): HasOne
    {
        return $this->hasOne(PortConnection::class, 'port_a_id');
    }

    public function connectionAsB(): HasOne
    {
        return $this->hasOne(PortConnection::class, 'port_b_id');
    }

    /**
     * Human-readable link speed for the ports table (e.g. `1 Gbps`), or an
     * em dash when unknown.
     */
    public function getSpeedLabelAttribute(): string
    {
        $bps = $this->speed_bps;

        if ($bps === null || $bps <= 0) {
            return '—';
        }

        foreach ([[1_000_000_000, 'Gbps'], [1_000_000, 'Mbps'], [1_000, 'Kbps']] as [$unit, $suffix]) {
            if ($bps >= $unit) {
                $value = rtrim(rtrim(number_format($bps / $unit, 2, '.', ''), '0'), '.');

                return $value.' '.$suffix;
            }
        }

        return $bps.' bps';
    }
}
