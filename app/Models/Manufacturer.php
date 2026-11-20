<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description'])]
class Manufacturer extends Model
{
    use HasFactory;

    public function deviceTypes(): HasMany
    {
        return $this->hasMany(DeviceType::class);
    }
}
