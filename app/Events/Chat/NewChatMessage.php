<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Support\ChatMessagePayload;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was posted.
 *
 * Every event in this namespace implements ShouldBroadcastNow and NEVER
 * ShouldBroadcast or ShouldQueue. This installation runs
 * QUEUE_CONNECTION=database and its only worker is the scheduled
 * `queue:work --queue=emails,default --stop-when-empty` in routes/console.php,
 * which fires once a minute. A queued broadcast would therefore sit in the
 * `jobs` table for up to a minute and the "real-time" chat would not be
 * real-time. ShouldBroadcastNow publishes inside the request instead, which is
 * why the payloads here are ids plus rendered text rather than object graphs,
 * and why config/broadcasting.php pins a short publish timeout.
 *
 * A test asserts this for the whole directory by reflection, so a future event
 * cannot quietly go back to being queued.
 */
class NewChatMessage implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public ChatConversationMessage $message) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.conversation.'.$this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.new';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $conversation = $this->message->conversation ?? ChatConversation::find($this->message->conversation_id);

        if ($conversation?->isCustomerInbox() === true) {
            $this->message->loadMissing(['user', 'attachments', 'entityLinks.linkable']);

            return ['message' => ChatMessagePayload::forClient($this->message)];
        }

        return ['message' => ChatMessagePayload::for($this->message)];
    }
}
