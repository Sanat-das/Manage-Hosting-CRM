<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'vlan_id', 'description', 'datacenter_id', 'subnet_id'])]
class Vlan extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'vlan_id' => 'integer',
        ];
    }

    public function datacenter(): BelongsTo
    {
        return $this->belongsTo(Datacenter::class);
    }
}
