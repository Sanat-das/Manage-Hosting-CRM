<?php

namespace App\Models;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'order_id', 'name', 'type', 'registrar_id', 'registrar', 'registration_date', 'registration_period', 'expiry_date', 'next_due_date', 'next_invoice_date', 'recurring_amount', 'payment_method', 'subscription_id', 'auto_renew', 'privacy_enabled', 'nameservers', 'dns_records', 'auth_code', 'lock_status', 'dns_management', 'email_forwarding', 'id_protection', 'status'])]
class Domain extends Model
{
    protected $casts = [
        'registration_date' => 'date',
        'registration_period' => 'integer',
        'expiry_date' => 'date',
        'next_due_date' => 'date',
        'next_invoice_date' => 'date',
        'recurring_amount' => 'decimal:2',
        'auto_renew' => 'boolean',
        'privacy_enabled' => 'boolean',
        'lock_status' => 'boolean',
        'dns_management' => 'boolean',
        'email_forwarding' => 'boolean',
        'id_protection' => 'boolean',
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
     * True when the domain has expired or its expiry date has passed.
     */
    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /**
     * Days remaining until expiry (negative when past), or null without a date.
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }

        return (int) $this->expiry_date->diffInDays(now(), false);
    }

    /**
     * True when the expiry date is today or within the next $days days.
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        if ($this->expiry_date === null) {
            return false;
        }

        $until = $this->daysUntilExpiry();

        return $until !== null && $until >= 0 && $until <= $days;
    }
}
