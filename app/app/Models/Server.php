<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'ip_address', 'panel_type', 'api_url', 'api_key', 'api_username', 'max_accounts', 'status'])]
class Server extends Model
{
    protected function casts(): array
    {
        return [
            'max_accounts' => 'integer',
        ];
    }

    public function hostingAccounts(): HasMany
    {
        return $this->hasMany(HostingAccount::class);
    }

    public function groupMembers(): HasMany
    {
        return $this->hasMany(ServerGroupMember::class);
    }
}
