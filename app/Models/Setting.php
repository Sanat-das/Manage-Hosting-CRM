<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['setting_key', 'setting_value', 'group'])]
class Setting extends Model
{
}
