<?php

namespace App\Models;

use App\Models\IpSubnet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subnet_id', 'ip_address', 'ip_version', 'type', 'assigned_to_type', 'assigned_to_id', 'inventory_asset_id', 'ptr_record', 'notes', 'last_seen_at'])]
class IpAddress extends Model
{
    protected $table = 'ip_addresses';

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
}
