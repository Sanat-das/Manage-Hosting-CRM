<?php

namespace App\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'pool_type', 'parent_id', 'total_capacity', 'unit', 'server_id', 'datacenter_id', 'status'])]
class ResourcePool extends Model
{
    protected $table = 'resource_pools';

    protected function casts(): array
    {
        return [
            'total_capacity' => 'decimal:4',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
