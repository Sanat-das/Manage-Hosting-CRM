<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ticket_id', 'from_department', 'to_department', 'assigned_to', 'assigned_from', 'actor_id', 'note', 'created_at'])]
class TicketTransfer extends Model
{
    /**
     * Append-only audit — only created_at, no updated_at.
     */
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'assigned_to' => 'integer',
            'assigned_from' => 'integer',
            'actor_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
