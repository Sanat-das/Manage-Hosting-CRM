<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[Fillable(['name', 'slug', 'color', 'description'])]
class Tag extends Model
{
    use HasFactory;

    public function assets(): MorphToMany
    {
        return $this->morphedByMany(Device::class, 'taggable');
    }
}
