<?php

namespace App\Models;

use App\Models\IpSubnet;
use App\Models\Rack;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'address', 'city', 'state', 'country', 'timezone', 'status'])]
class Datacenter extends Model
{
    protected $table = 'datacenters';

    public function racks(): HasMany
    {
        return $this->hasMany(Rack::class);
    }

    public function subnets(): HasMany
    {
        return $this->hasMany(IpSubnet::class);
    }
}
