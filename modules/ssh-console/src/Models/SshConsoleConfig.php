<?php

declare(strict_types=1);

namespace Modules\SshConsole\Models;

use App\Models\HostingAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-account SSH connection settings for the web terminal. All secrets use
 * Laravel's encrypted cast; blank updates must preserve existing values (the
 * controller handles keep-if-blank semantics).
 */
class SshConsoleConfig extends Model
{
    protected $table = 'ssh_console_configs';

    protected $fillable = [
        'hosting_account_id',
        'host',
        'port',
        'username',
        'password_encrypted',
        'private_key_encrypted',
        'passphrase_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password_encrypted' => 'encrypted',
            'private_key_encrypted' => 'encrypted',
            'passphrase_encrypted' => 'encrypted',
        ];
    }

    public function hostingAccount(): BelongsTo
    {
        return $this->belongsTo(HostingAccount::class);
    }
}
