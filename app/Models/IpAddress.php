<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['subnet_id', 'ip_address', 'ip_version', 'type', 'assigned_to_type', 'assigned_to_id', 'inventory_asset_id', 'ptr_record', 'notes', 'last_seen_at'])]
class IpAddress extends Model
{
    protected $table = 'ip_addresses';

    public const TYPES = ['gateway', 'broadcast', 'network', 'reserved', 'available', 'assigned', 'floating', 'nat'];

    public const STATUSES = ['available', 'assigned', 'reserved', 'gateway', 'broadcast', 'network', 'floating', 'nat'];

    protected $appends = [];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function subnet(): BelongsTo
    {
        return $this->belongsTo(IpSubnet::class, 'subnet_id');
    }

    public function assignedTo(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'assigned_to_type', 'assigned_to_id');
    }

    public function inventoryAsset(): BelongsTo
    {
        return $this->belongsTo(InventoryAsset::class, 'inventory_asset_id');
    }

    /**
     * Computed status — assigned when leased to any polymorphic owner,
     * otherwise falls back to the `type` column which already encodes
     * available/reserved/gateway/etc. This matches IpAssignmentService
     * (where available == assigned_to_type IS NULL) and the seeder plan.
     */
    public function getStatusAttribute(): string
    {
        if ($this->assigned_to_type !== null && $this->assigned_to_type !== '') {
            return 'assigned';
        }

        $type = $this->attributes['type'] ?? 'available';

        return in_array($type, self::STATUSES, true) ? $type : 'available';
    }

    public function getIsAssignedAttribute(): bool
    {
        return $this->assigned_to_type !== null && $this->assigned_to_type !== '';
    }

    public function scopeAssigned($query)
    {
        return $query->whereNotNull('assigned_to_type');
    }

    public function scopeAvailable($query)
    {
        return $query->whereNull('assigned_to_type')->where('type', 'available');
    }

    public function scopeByStatus($query, string $status)
    {
        if ($status === 'assigned') {
            return $query->whereNotNull('assigned_to_type');
        }
        if ($status === 'available') {
            return $query->whereNull('assigned_to_type')->where('type', 'available');
        }

        return $query->where('type', $status)->whereNull('assigned_to_type');
    }
}
