<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'subnet_cidr', 'gateway', 'netmask', 'ip_version', 'network_type', 'vlan_id', 'datacenter_id', 'total_addresses', 'used_addresses', 'reserved_count', 'description', 'status'])]
class IpSubnet extends Model
{
    protected $table = 'ip_subnets';

    protected function casts(): array
    {
        return [
            'total_addresses' => 'integer',
            'used_addresses' => 'integer',
            'reserved_count' => 'integer',
        ];
    }

    public function datacenter(): BelongsTo
    {
        return $this->belongsTo(Datacenter::class);
    }

    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class);
    }

    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class, 'subnet_id');
    }
}
