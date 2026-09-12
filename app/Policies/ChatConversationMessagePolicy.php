<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChatConversationMessage;
use App\Models\User;

/**
 * Who may act on an individual message.
 *
 * Every decision starts from the conversation: if you cannot see the room you
 * cannot see, edit, delete or react to anything said in it.
 */
class ChatConversationMessagePolicy
{
    public function __construct(private readonly ChatConversationPolicy $conversations) {}

    public function view(User $user, ChatConversationMessage $message): bool
    {
        $conversation = $message->conversation;

        return $conversation !== null && $this->conversations->view($user, $conversation);
    }

    /**
     * Editing is the author's alone.
     *
     * Deliberately not extended to chat.manage: a moderator rewriting someone
     * else's words under their name is indistinguishable from forgery. A
     * moderator can delete (below), which is visible to everyone in the thread.
     */
    public function update(User $user, ChatConversationMessage $message): bool
    {
        if ($message->isDeleted() || $message->user_id === null) {
            return false;
        }

        if ($message->user_id !== $user->id) {
            return false;
        }

        $conversation = $message->conversation;

        return $conversation !== null && ! $conversation->isArchived();
    }

    /**
     * The author may retract; chat.manage may moderate. Either way the row is
     * soft-deleted, so the thread it anchors survives.
     */
    public function delete(User $user, ChatConversationMessage $message): bool
    {
        if ($message->isDeleted()) {
            return false;
        }

        $conversation = $message->conversation;

        if ($conversation === null || $conversation->isArchived()) {
            return false;
        }

        return $message->user_id === $user->id || $user->hasPermission('chat.manage');
    }

    public function react(User $user, ChatConversationMessage $message): bool
    {
        if ($message->isDeleted()) {
            return false;
        }

        $conversation = $message->conversation;

        return $conversation !== null
            && ! $conversation->isArchived()
            && $this->conversations->sendMessage($user, $conversation);
    }

    public function reply(User $user, ChatConversationMessage $message): bool
    {
        $conversation = $message->conversation;

        return $conversation !== null && $this->conversations->sendMessage($user, $conversation);
    }
}
