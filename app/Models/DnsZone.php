<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'zone_type', 'serial', 'refresh', 'retry', 'expire', 'ttl', 'master_nameserver', 'admin_email', 'status'])]
class DnsZone extends Model
{
    protected $table = 'dns_zones';

    protected function casts(): array
    {
        return [
            'serial' => 'integer',
            'refresh' => 'integer',
            'retry' => 'integer',
            'expire' => 'integer',
            'ttl' => 'integer',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class, 'zone_id');
    }
}
