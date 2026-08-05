<?php

namespace App\Models;

use App\Models\Server;
use App\Models\ServerGroup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['server_group_id', 'server_id', 'priority'])]
class ServerGroupMember extends Model
{
    public function group(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'server_group_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
