<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Models\ChatConversation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer opened a conversation and nobody has taken it yet.
 *
 * This is the one signal that makes the chat live for the people expected to
 * answer it. Without it a queued conversation is discoverable only by reloading
 * /admin/chat: the room has no staff participant until it is assigned, so it
 * raises no unread badge, and every other event in this namespace broadcasts on
 * `chat.conversation.{id}` — a channel no operator is subscribed to until they
 * have already opened the room they do not yet know exists.
 *
 * It therefore broadcasts on one shared operator channel instead, authorised in
 * routes/channels.php by `chat.manage` — the same permission
 * ChatConversationPolicy::operate() requires to take, transfer or close one of
 * these. A holder of plain `chat.view` is not in the answering pool and is not
 * told.
 *
 * ShouldBroadcastNow for the reason given in NewChatMessage: this installation
 * has no persistent queue worker, so a queued broadcast would announce the
 * waiting customer up to a minute late.
 */
class CustomerChatWaiting implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public ChatConversation $conversation) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.inbox')];
    }

    public function broadcastAs(): string
    {
        return 'chat.inbox.waiting';
    }

    /**
     * Enough to put a row in the sidebar and nothing more.
     *
     * Deliberately no message body. The subscribers here are entitled to read
     * the conversation — `chat.manage` is exactly what
     * ChatConversationPolicy::view() accepts for a customer inbox — but this
     * channel is shared and long-lived, and a queue announcement does not need
     * to carry what the customer wrote. The operator gets the text when they
     * open the room, through the endpoint that checks the policy per request.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => [
                'id' => (int) $this->conversation->id,
                'name' => $this->conversation->displayName(),
                'status' => $this->conversation->status,
                'department' => $this->conversation->department,
                'url' => route('admin.chat.index', ['c' => $this->conversation->id]),
            ],
        ];
    }
}
