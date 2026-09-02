<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ticket_id', 'user_id', 'message', 'html_body', 'is_staff', 'email_message_id', 'email_in_reply_to', 'from_email', 'raw_source', 'to', 'cc', 'bcc'])]
class TicketReply extends Model
{
    protected function casts(): array
    {
        return [
            'is_staff' => 'boolean',
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }
}
