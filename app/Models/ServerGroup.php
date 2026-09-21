<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'load_balancing', 'status', 'allowed_server_type'])]
class ServerGroup extends Model
{
    public $timestamps = false;

    protected $casts = [
        'status' => 'string',
        'allowed_server_type' => 'string',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(ServerGroupMember::class);
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'server_group_members');
    }
}
