<?php

namespace App\Models;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ticket_no', 'customer_id', 'subject', 'priority', 'status', 'department', 'assigned_to', 'last_reply_at'])]
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

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }
}
