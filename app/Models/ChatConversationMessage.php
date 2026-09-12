<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single message. Soft-deleted so a deleted thread parent still anchors its
 * replies — the UI shows "[deleted]" rather than dropping the branch.
 *
 * `guest_token` is not fillable: it identifies the guest author and is set by
 * ChatService from the validated session token, never from request input.
 */
#[Fillable(['conversation_id', 'user_id', 'parent_id', 'body', 'edited_at'])]
class ChatConversationMessage extends Model
{
    use HasFactory, SoftDeletes;

    /** Body shown in place of a soft-deleted message. */
    public const DELETED_PLACEHOLDER = '[deleted]';

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatReaction::class, 'message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatMessageAttachment::class, 'message_id');
    }

    public function entityLinks(): HasMany
    {
        return $this->hasMany(MessageEntityLink::class, 'message_id');
    }

    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function isThreadReply(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * The body as it should be shown — never leak the text of a deleted message.
     */
    public function visibleBody(): string
    {
        return $this->isDeleted() ? self::DELETED_PLACEHOLDER : (string) $this->body;
    }

    public function authorName(): string
    {
        return $this->user?->full_name
            ?? $this->conversation?->guest_name
            ?? 'Guest';
    }
}
