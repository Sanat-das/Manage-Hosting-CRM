<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * HostingAccount — core service record. `username`/`username_prefix`/`panel_account_id`/
 * `disk_quota`/`bandwidth_quota`/`password` (panel password) are legacy and no longer
 * collected through the admin UI. Columns kept nullable for backward compat. `host_name`
 * is the product's identifier across the application (admin/client UI, asset links) —
 * auto-generated on creation when left blank.
 */
#[Fillable(['customer_id', 'product_id', 'server_id', 'order_id', 'username',
    'domain', 'host_name', 'disk_quota', 'disk_used', 'bandwidth_quota', 'bandwidth_used', 'panel_account_id', 'username_prefix',
    'password', 'status', 'suspended_reason', 'suspended_at', 'next_due_date', 'notes'])]
class HostingAccount extends Model
{
    protected $casts = [
        'disk_quota' => 'integer',
        'disk_used' => 'integer',
        'bandwidth_quota' => 'integer',
        'bandwidth_used' => 'integer',
        'suspended_at' => 'datetime',
        'next_due_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $account) {
            if (empty($account->getAttributes()['host_name'] ?? null)) {
                $account->host_name = self::generateHostName();
            }
        });
    }

    /**
     * Display fallback for accounts created before host_name existed.
     */
    public function getHostNameAttribute(?string $value): string
    {
        return $value ?: self::fallbackHostName($this->id);
    }

    /**
     * The same fallback the accessor applies, reachable without a model
     * instance.
     *
     * Views that join hosting_accounts in raw SQL read the host_name COLUMN,
     * which is null on every account predating the field — bypassing the
     * accessor and rendering an empty cell where the account page shows
     * "HOST-00028". The snmp-monitor dashboard is the one such call site
     * today; it uses this rather than re-implementing the format and
     * drifting from it. Keep the accessor above delegating here so the two
     * can never disagree.
     */
    public static function fallbackHostName(int|string $id): string
    {
        return 'HOST-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    private static function generateHostName(): string
    {
        do {
            $candidate = 'host-'.Str::lower(Str::random(8));
        } while (self::where('host_name', $candidate)->exists());

        return $candidate;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * IP addresses currently leased to this account. The lease lives on the
     * polymorphic ip_addresses pair (assigned_to_type / assigned_to_id), so
     * an account can hold a public and a private lease at once.
     */
    public function ipAddresses(): MorphMany
    {
        return $this->morphMany(IpAddress::class, 'assigned_to');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(HostingNote::class);
    }

    /**
     * Percentage of the disk quota in use (0-100).
     */
    public function diskUsagePercent(): float
    {
        return $this->disk_quota > 0 ? round(($this->disk_used / $this->disk_quota) * 100, 1) : 0.0;
    }

    /**
     * Percentage of the bandwidth quota in use (0-100).
     */
    public function bandwidthUsagePercent(): float
    {
        return $this->bandwidth_quota > 0 ? round(($this->bandwidth_used / $this->bandwidth_quota) * 100, 1) : 0.0;
    }
}
