<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Models\ChatConversationMessage;
use App\Support\ChatMessagePayload;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message's text was changed by its author.
 */
class ChatMessageEdited implements ShouldBroadcastNow
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
        return 'chat.message.edited';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['message' => ChatMessagePayload::for($this->message)];
    }
}
