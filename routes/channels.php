<?php

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Authorisation for the websocket channels the Slack-like chat subscribes to.
| A closure returning false here is the ONLY thing standing between a private
| channel and any authenticated staff member, so each one re-checks membership
| against the database rather than trusting anything the client sent.
|
| The shared rule below is a closure, not a named function: this file is
| require'd on every application boot, and a named function would fatal with
| "cannot redeclare" the second time the framework boots in one process — which
| is exactly what the test suite does.
|
*/

/**
 * May this user listen to this conversation?
 *
 * Mirrors the rules the HTTP layer enforces:
 *  - a customer inbox is readable by its participants and by staff holding
 *    chat.manage (the operator queue); it is never a staff channel;
 *  - a private channel/DM/group DM is readable only by its participants;
 *  - a public channel is readable by any panel user holding chat.view, which
 *    is what "public" means — joining is not a precondition for reading.
 */
$mayListen = static function (?User $user, ChatConversation $conversation): bool {
    if ($user === null) {
        return false;
    }

    $isParticipant = ChatParticipant::query()
        ->where('conversation_id', $conversation->id)
        ->where('user_id', $user->id)
        ->exists();

    if ($conversation->isCustomerInbox()) {
        return $isParticipant || $user->hasPermission('chat.manage');
    }

    if ($isParticipant) {
        return true;
    }

    return ! $conversation->is_private
        && $conversation->type === ChatConversation::TYPE_CHANNEL
        && $user->hasPermission('chat.view');
};

/**
 * Messages, edits, deletes and reactions for one conversation.
 */
Broadcast::channel('chat.conversation.{conversationId}', static function (User $user, int $conversationId) use ($mayListen) {
    $conversation = ChatConversation::find($conversationId);

    return $conversation !== null && $mayListen($user, $conversation);
});

/**
 * Who is currently typing in one conversation. A presence channel so the
 * client learns when someone stops without needing a timeout heartbeat of its
 * own, and gated identically to the conversation itself.
 */
Broadcast::channel('chat.typing.{conversationId}', static function (User $user, int $conversationId) use ($mayListen) {
    $conversation = ChatConversation::find($conversationId);

    if ($conversation === null || ! $mayListen($user, $conversation)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->full_name];
});

/**
 * Panel-wide presence: the online dots in the sidebar. Any panel user who can
 * see the chat at all appears here — it carries no message content.
 */
Broadcast::channel('chat.presence', static function (User $user) {
    if (! $user->hasPermission('chat.view')) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->full_name];
});
