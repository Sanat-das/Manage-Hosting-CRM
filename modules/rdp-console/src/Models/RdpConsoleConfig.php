<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Models;

use App\Models\HostingAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RdpConsoleConfig extends Model
{
    protected $table = 'rdp_console_configs';

    protected $fillable = ['hosting_account_id', 'host', 'port', 'username', 'password_encrypted', 'domain'];

    protected function casts(): array
    {
        return [
            'password_encrypted' => 'encrypted',
            'port' => 'integer',
        ];
    }

    public function hostingAccount(): BelongsTo
    {
        return $this->belongsTo(HostingAccount::class);
    }
}
