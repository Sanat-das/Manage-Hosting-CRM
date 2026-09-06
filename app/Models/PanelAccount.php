<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A control-panel account a provisioning module created on a remote server.
 *
 * Shared by every panel module (see App\Contracts\Module\AbstractPanelModule);
 * `panel` says which one owns the row.
 *
 * `password_encrypted` uses the `encrypted` cast, so rows must only ever be
 * written through the model — a raw `DB::table()->update()` would store
 * plaintext and every later read would throw. Same trap as
 * ticket_departments.imap_password, which is why that column ended up on a
 * tolerant custom cast; here the writes are all in one place, so the strict
 * cast is safe.
 */
#[Fillable([
    'service_instance_id', 'server_id', 'panel', 'username', 'domain',
    'password_encrypted', 'plan', 'external_id', 'meta', 'status',
    'provisioned_at', 'suspended_at', 'terminated_at',
])]
#[Hidden(['password_encrypted'])]
class PanelAccount extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUS_PENDING = 'pending';

    protected $table = 'panel_accounts';

    protected function casts(): array
    {
        return [
            'password_encrypted' => 'encrypted',
            'meta' => 'array',
            'provisioned_at' => 'datetime',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class, 'service_instance_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
