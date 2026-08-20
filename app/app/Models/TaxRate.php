<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'rate', 'is_active'])]
class TaxRate extends Model
{
    protected $casts = [
        'rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
