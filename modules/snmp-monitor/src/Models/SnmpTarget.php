<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Models;

use App\Models\HostingAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SNMP polling target per hosting account. A null `host` is resolved
 * from the account's IPAM leases by TargetService at ensure/poll time.
 * Holds NO credentials — SNMP community/auth secrets stay in the product
 * module config.
 */
class SnmpTarget extends Model
{
    public const OS_LINUX = 'linux';

    public const OS_WINDOWS = 'windows';

    public const STATUS_UP = 'up';

    public const STATUS_DOWN = 'down';

    public const STATUS_UNKNOWN = 'unknown';

    protected $table = 'snmp_targets';

    protected $fillable = [
        'hosting_account_id',
        'host',
        'port',
        'target_os',
        'poll_interval',
        'enabled',
        'status',
        'consecutive_failures',
        'last_polled_at',
        'next_poll_at',
        'last_response_ms',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'poll_interval' => 'integer',
            'enabled' => 'boolean',
            'status' => 'string',
            'consecutive_failures' => 'integer',
            'last_polled_at' => 'datetime',
            'next_poll_at' => 'datetime',
            'last_response_ms' => 'integer',
        ];
    }

    public function hostingAccount(): BelongsTo
    {
        return $this->belongsTo(HostingAccount::class);
    }
}
