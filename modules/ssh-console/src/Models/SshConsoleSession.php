<?php

declare(strict_types=1);

namespace Modules\SshConsole\Models;

use App\Models\HostingAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail of web SSH terminal sessions: who opened a shell on which
 * account, from which IP, and how the session ended. status is 'opened'
 * while active, then 'closed' or 'failed'. Keystrokes and output are never
 * persisted.
 */
class SshConsoleSession extends Model
{
    protected $table = 'ssh_console_sessions';

    protected $fillable = [
        'hosting_account_id',
        'admin_user_id',
        'token',
        'ip_address',
        'status',
        'started_at',
        'ended_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function hostingAccount(): BelongsTo
    {
        return $this->belongsTo(HostingAccount::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * Idempotent finalization: only transitions an 'opened' row to a terminal
     * state. Later calls (e.g. close endpoint racing the streamer cleanup)
     * leave already-final rows untouched.
     */
    public function finalize(string $status, ?string $error = null): void
    {
        if ($this->status !== 'opened') {
            return;
        }

        $updated = static::query()
            ->where('id', $this->id)
            ->where('status', 'opened')
            ->update([
                'status' => $status,
                'error' => $error,
                'ended_at' => now(),
            ]);

        if ($updated > 0) {
            $this->setRawAttributes(array_merge($this->attributes, [
                'status' => $status,
                'error' => $error,
                'ended_at' => now(),
            ]));
        }
    }
}
