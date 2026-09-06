<?php

namespace App\Models;

use App\Models\Concerns\HasRoles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['email', 'password_hash', 'role', 'first_name', 'last_name', 'phone', 'company', 'address', 'address_line1', 'address_line2', 'city', 'state', 'postcode', 'country', 'status', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'ticket_signature'])]
#[Hidden(['password_hash', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    use HasRoles;

    /**
     * The database column that holds the hashed password.
     *
     * The reference schema names this column `password_hash` instead of
     * Laravel's default `password`. Overriding this method keeps the whole
     * authentication stack (guard, password broker, validation rules)
     * working against the custom column.
     */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /**
     * The database column that holds the hashed password.
     *
     * Laravel's rehash-on-login path (EloquentUserProvider
     * ::rehashPasswordIfRequired, DatabaseUserProvider::updatePassword) writes
     * through this name. It must match getAuthPassword() or every successful
     * login of a user whose hash needs rehashing (e.g. an old cost) would try
     * to write a non-existent `password` column and throw a QueryException.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * Display name used by AdminLTE's user menu / navbar.
     */
    public function getAuthName(): string
    {
        return trim($this->first_name.' '.$this->last_name) ?: $this->email;
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name) ?: $this->email;
    }

    /**
     * AdminLTE's navbar/user menu reads $user->name. There is no `name`
     * column in the schema, so expose the full name via an accessor.
     */
    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class, 'user_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }

    public function ticketDepartments(): BelongsToMany
    {
        return $this->belongsToMany(TicketDepartment::class, 'ticket_department_user')->withTimestamps();
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'role' => 'string',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Ecommerce formatted address - combines structured fields, falls back to legacy address.
     */
    public function getFormattedAddressAttribute(): ?string
    {
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->state,
            $this->postcode,
            $this->country,
        ], fn ($v) => $v !== null && $v !== '');

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return $this->address ?: null;
    }

    public function getBillingAddressLineAttribute(): ?string
    {
        return $this->formatted_address;
    }
}
