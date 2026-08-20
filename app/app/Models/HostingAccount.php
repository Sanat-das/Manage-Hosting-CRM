<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['customer_id', 'product_id', 'server_id', 'order_id', 'username',
    'domain', 'disk_quota', 'disk_used', 'bandwidth_quota', 'bandwidth_used', 'panel_account_id', 'username_prefix',
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
