<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'slug', 'category', 'unit', 'description'])]
class ResourceType extends Model
{
    protected $table = 'resource_types';
}
