<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ticket_no', 'customer_id', 'guest_email', 'guest_name', 'subject', 'priority', 'status', 'department', 'assigned_to', 'last_reply_at'])]
class Ticket extends Model
{
    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'priority' => 'string',
            'status' => 'string',
            'department' => 'string',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->customer) {
            return $this->customer->full_name;
        }
        if ($this->guest_name) {
            return $this->guest_name;
        }
        if ($this->guest_email) {
            return $this->guest_email;
        }
        return 'Guest';
    }

    public function getDisplayEmailAttribute(): ?string
    {
        if ($this->customer?->user?->email) {
            return $this->customer->user->email;
        }
        return $this->guest_email;
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(TicketTransfer::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Constrain the query to tickets visible to the given staff/admin user.
     * Admins see everything. Staff are limited to the departments they
     * belong to via `ticket_department_user`; with none, they see nothing.
     *
     * Client visibility is untouched here — it is scoped by `customer_id`
     * in the client controllers, not by this scope.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        $slugs = $user->ticketDepartments()->pluck('slug');

        if ($slugs->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('department', $slugs);
    }
}
