<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One emoji reaction by one user on one message. The unique index on
 * (message_id, user_id, emoji) is what makes toggling a delete-or-insert
 * rather than a counter that can drift out of step with reality.
 */
#[Fillable(['message_id', 'user_id', 'emoji'])]
class ChatReaction extends Model
{
    use HasFactory;

    /**
     * The emoji a user may react with. A whitelist rather than free text:
     * the column is 32 bytes and an unbounded value is a spoofing surface.
     */
    public const ALLOWED = ['👍', '👎', '😀', '😂', '🎉', '❤️', '👀', '🙏', '🔥', '✅', '❌', '🚀'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatConversationMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function isAllowed(string $emoji): bool
    {
        return in_array($emoji, self::ALLOWED, true);
    }
}
