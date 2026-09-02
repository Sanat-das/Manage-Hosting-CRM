<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SSL certificate registry entry.
 *
 * Fresh module: the reference CRM has no SSL module at all, so this model
 * follows the Session 2 conventions (Fillable/Cast attributes, typed
 * relationships) rather than a ported reference shape.
 */
#[Fillable(['customer_id', 'domain_name', 'certificate_type', 'provider', 'status', 'issue_date', 'expiry_date', 'order_id', 'notes'])]
class SslCertificate extends Model
{
    protected $casts = [
        'certificate_type' => 'string',
        'status' => 'string',
        'issue_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * True when the certificate has expired or its expiry date has passed.
     */
    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /**
     * True when the certificate is still valid but expires within the next
     * N days (default 30) — the "expiring soon" quick filter.
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        if ($this->expiry_date === null || $this->status !== 'active') {
            return false;
        }

        return $this->expiry_date->isFuture()
            && $this->expiry_date->isBefore(now()->addDays($days)->endOfDay());
    }
}
