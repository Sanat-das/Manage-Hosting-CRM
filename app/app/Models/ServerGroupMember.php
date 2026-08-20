<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['server_group_id', 'server_id', 'priority'])]
class ServerGroupMember extends Model
{
    /**
     * The server_group_members table has no timestamp columns — disable
     * Eloquent's automatic timestamps so create/update/sync don't write
     * nonexistent columns.
     */
    public $timestamps = false;

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'server_group_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
