<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['zone_id', 'name', 'type', 'content', 'ttl', 'priority', 'service_id', 'status'])]
class DnsRecord extends Model
{
    protected $table = 'dns_records';

    protected function casts(): array
    {
        return [
            'ttl' => 'integer',
            'priority' => 'integer',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'zone_id');
    }
}
