<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'load_balancing', 'status'])]
class ServerGroup extends Model
{
    /**
     * The server_groups table carries only created_at (DB default), no
     * updated_at column — disable Eloquent's automatic timestamps so
     * create/update don't write a nonexistent column.
     */
    public $timestamps = false;

    protected $casts = [
        'status' => 'string',
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
