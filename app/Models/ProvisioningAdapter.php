<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'adapter_class', 'method', 'config_schema', 'api_endpoint_template', 'is_enabled'])]
class ProvisioningAdapter extends Model
{
    protected $table = 'provisioning_adapters';

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config_schema' => 'array',
        ];
    }
}
