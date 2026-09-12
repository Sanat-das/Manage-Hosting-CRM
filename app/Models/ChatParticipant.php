<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of a conversation, and the read cursor.
 *
 * `last_read_message_id` is the ONLY record of what a participant has read —
 * there is no chat_read_state table. Unread count is derived from it.
 *
 * `guest_token` is not fillable; it is the guest's credential and is written
 * explicitly by ChatService.
 */
#[Fillable(['conversation_id', 'user_id', 'role', 'joined_at', 'last_read_message_id'])]
class ChatParticipant extends Model
{
    use HasFactory;

    public const ROLE_MEMBER = 'member';

    public const ROLE_ADMIN = 'admin';

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(ChatConversationMessage::class, 'last_read_message_id');
    }

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
