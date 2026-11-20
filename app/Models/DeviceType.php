<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['manufacturer_id', 'model', 'slug', 'part_number', 'u_height', 'is_full_depth', 'power_draw_watts', 'description', 'category'])]
class DeviceType extends Model
{
    use HasFactory;

    /**
     * Selectable values of device_types.category — the single source of
     * truth for the controller validation and the admin form/filter
     * dropdowns. Category is mandatory: it is what devices inherit as their
     * NetBox-style type classification.
     */
    public const CATEGORIES = [
        'server' => 'Server',
        'switch' => 'Switch',
        'pdu' => 'PDU',
        'other_hardware' => 'Other hardware',
    ];

    protected function casts(): array
    {
        return [
            'u_height' => 'integer',
            'is_full_depth' => 'boolean',
            'power_draw_watts' => 'integer',
        ];
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
