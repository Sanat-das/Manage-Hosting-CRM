<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;

/**
 * Who may see and act on a conversation.
 *
 * This is the single authority for chat visibility: the HTTP layer, the Blade
 * views and the websocket channel authorisation in routes/channels.php all
 * resolve through `view()`. Keeping one copy matters — a second copy that
 * drifts is how a private channel ends up readable over the socket by someone
 * the page itself would have turned away.
 */
class ChatConversationPolicy
{
    /**
     * Read a conversation.
     *
     *  - a customer inbox belongs to its participants and to the operator pool
     *    (chat.manage); it is never a staff channel and ordinary staff cannot
     *    read one they are not assigned to;
     *  - a participant of anything may read it;
     *  - a public channel is readable by any holder of chat.view — that is what
     *    "public" means, and joining is not a precondition for reading;
     *  - a private channel, DM or group DM is readable by participants only.
     */
    public function view(User $user, ChatConversation $conversation): bool
    {
        $isParticipant = $this->isParticipant($user, $conversation);

        if ($conversation->isCustomerInbox()) {
            return $isParticipant || $user->hasPermission('chat.manage');
        }

        if ($isParticipant) {
            return true;
        }

        if ($conversation->is_private || $conversation->type !== ChatConversation::TYPE_CHANNEL) {
            return false;
        }

        if (! $user->hasPermission('chat.view')) {
            return false;
        }

        return $this->mayReachDepartment($user, $conversation);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('chat.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('chat.create_channel') || $user->hasPermission('chat.manage');
    }

    /**
     * Rename, re-topic, archive, or change the membership of a conversation.
     *
     * The creator, a participant holding the conversation's own admin role, or
     * anyone with chat.manage. A plain member cannot rename the room out from
     * under everyone else.
     */
    public function update(User $user, ChatConversation $conversation): bool
    {
        if ($user->hasPermission('chat.manage')) {
            return true;
        }

        if ($conversation->created_by === $user->id) {
            return true;
        }

        return ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->where('role', ChatParticipant::ROLE_ADMIN)
            ->exists();
    }

    public function archive(User $user, ChatConversation $conversation): bool
    {
        return $this->update($user, $conversation);
    }

    public function addMember(User $user, ChatConversation $conversation): bool
    {
        return $this->update($user, $conversation);
    }

    public function removeMember(User $user, ChatConversation $conversation): bool
    {
        return $this->update($user, $conversation);
    }

    /**
     * Post into a conversation.
     *
     * Reading is not enough on its own: an archived conversation is frozen, and
     * a non-participant may read a public channel but has to join it to speak.
     */
    public function sendMessage(User $user, ChatConversation $conversation): bool
    {
        if ($conversation->isArchived()) {
            return false;
        }

        if (! $this->view($user, $conversation)) {
            return false;
        }

        if ($this->isParticipant($user, $conversation)) {
            return true;
        }

        // Operators answer a queued customer inbox before being attached to it.
        return $conversation->isCustomerInbox() && $user->hasPermission('chat.manage');
    }

    /**
     * Join a conversation of one's own accord — public channels only. Private
     * rooms, DMs and customer inboxes are joined by invitation or assignment.
     */
    public function join(User $user, ChatConversation $conversation): bool
    {
        if ($conversation->isArchived()) {
            return false;
        }

        if ($conversation->type !== ChatConversation::TYPE_CHANNEL || $conversation->is_private) {
            return false;
        }

        return $user->hasPermission('chat.view') && $this->mayReachDepartment($user, $conversation);
    }

    public function leave(User $user, ChatConversation $conversation): bool
    {
        return $this->isParticipant($user, $conversation);
    }

    /**
     * Take, transfer, close or rate a customer conversation.
     */
    public function operate(User $user, ChatConversation $conversation): bool
    {
        return $conversation->isCustomerInbox() && $user->hasPermission('chat.manage');
    }

    private function isParticipant(User $user, ChatConversation $conversation): bool
    {
        return ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * A channel tied to a support department is visible only to that
     * department's staff, mirroring how ticket visibility already works. The
     * pivot is `ticket_department_user`, matched on the department's slug.
     *
     * A conversation with no department is open to everyone who passed the
     * permission check above.
     */
    private function mayReachDepartment(User $user, ChatConversation $conversation): bool
    {
        if (($conversation->department ?? '') === '') {
            return true;
        }

        if ($user->hasPermission('chat.manage')) {
            return true;
        }

        return $user->ticketDepartments()
            ->where('slug', $conversation->department)
            ->exists();
    }
}
