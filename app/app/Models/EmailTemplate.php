<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'subject', 'body', 'status'])]
class EmailTemplate extends Model
{
    protected $casts = [
        'status' => 'string',
    ];
}
