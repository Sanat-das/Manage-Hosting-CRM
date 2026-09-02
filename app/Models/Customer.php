<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Notifications\Notifiable;

#[Fillable(['user_id', 'company', 'tax_id', 'state_code', 'balance', 'credit', 'status'])]
class Customer extends Model
{
    use Notifiable;

    protected $casts = [
        'balance' => 'decimal:2',
        'credit' => 'decimal:2',
        'status' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Reference-style customer display id (#CLT-xxxxx).
     */
    public function getDisplayIdAttribute(): string
    {
        return '#CLT-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    /**
     * The customer's full display name, from the linked user row.
     */
    public function getFullNameAttribute(): string
    {
        return $this->user?->full_name ?? $this->company ?? 'Customer';
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Payments made against the customer's invoices.
     *
     * There is no payments.customer_id column — payments hang off invoices
     * (payments.invoice_id), so the relation hops through the invoices table.
     */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(
            Payment::class,
            Invoice::class,
            'customer_id', // FK on invoices → customers
            'invoice_id',  // FK on payments → invoices
            'id',          // local key on customers
            'id'           // local key on invoices
        );
    }

    public function hostingAccounts(): HasMany
    {
        return $this->hasMany(HostingAccount::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(CustomerWallet::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }
}
