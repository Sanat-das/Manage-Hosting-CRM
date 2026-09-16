<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\ChatService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved reply.
 *
 * `user_id` null is the shared library; `user_id` set is one person's own.
 * That single column is the whole visibility rule, and `visibleTo()` is the
 * only place it is expressed — a second copy of "shared OR mine" in a
 * controller is how one of them ends up returning everyone's personal replies.
 *
 * `created_by` records the author of a shared reply and is deliberately never
 * consulted for authorisation: editing a shared reply takes chat.manage, not
 * having been the one who wrote it.
 */
#[Fillable(['title', 'shortcut', 'body', 'department', 'user_id', 'created_by'])]
class ChatCannedReply extends Model
{
    use HasFactory;

    /** As long as a chat message, because that is what it becomes. */
    public const MAX_BODY_LENGTH = ChatService::MAX_BODY_LENGTH;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    /**
     * The replies this user may see: the shared library plus their own.
     *
     * Ordered shared-first so the library an admin curates leads the picker,
     * with personal replies after it.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('user_id')
            ->orWhere('user_id', $user->id));
    }

    /**
     * Did this user create it as their own?
     *
     * Used by the controller to decide whether chat.manage is required, so it
     * answers false for every shared reply — including one this user wrote.
     */
    public function belongsToUser(User $user): bool
    {
        return $this->user_id !== null && (int) $this->user_id === (int) $user->id;
    }
}
