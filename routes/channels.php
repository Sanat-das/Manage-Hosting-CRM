<?php

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Authorisation for the websocket channels the Slack-like chat subscribes to.
| A closure returning false here is the ONLY thing standing between a private
| conversation and any authenticated panel user.
|
| Every decision is delegated to ChatConversationPolicy, which is what the
| controllers and views use too. A second copy of the rules here is how a
| private channel ends up readable over the socket by someone the page itself
| would have turned away.
|
*/

/**
 * Messages, edits, deletes and reactions for one conversation.
 */
Broadcast::channel('chat.conversation.{conversationId}', static function (User $user, int $conversationId) {
    $conversation = ChatConversation::find($conversationId);

    return $conversation !== null && $user->can('view', $conversation);
});

/**
 * Who is currently typing in one conversation. A presence channel so the client
 * learns when someone stops without a heartbeat of its own, and gated
 * identically to the conversation itself.
 */
Broadcast::channel('chat.typing.{conversationId}', static function (User $user, int $conversationId) {
    $conversation = ChatConversation::find($conversationId);

    if ($conversation === null || ! $user->can('view', $conversation)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->full_name];
});

/**
 * The operator queue: one shared channel that announces a customer conversation
 * nobody has taken yet (CustomerChatWaiting).
 *
 * Gated on `chat.manage` rather than `chat.view`, matching
 * ChatConversationPolicy::operate() and ::view() — `chat.manage` is what makes
 * a user a member of the answering pool and what lets them read an inbox room
 * they are not assigned to. A `chat.view` holder who is not an operator has no
 * business being told that a stranger's conversation exists.
 *
 * Private rather than presence: subscribers here do not need each other's
 * roster, and a presence channel would publish one.
 */
Broadcast::channel('chat.inbox', static function (User $user) {
    return $user->hasPermission('chat.manage');
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
