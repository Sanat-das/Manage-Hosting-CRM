<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was retracted.
 *
 * The payload carries ids only. The body is deliberately absent: a client that
 * has already rendered the text replaces it with the tombstone, and a client
 * that has not must never receive it.
 */
class ChatMessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $messageId,
        public int $conversationId,
        public ?int $parentId = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->messageId,
            'conversation_id' => $this->conversationId,
            'parent_id' => $this->parentId,
        ];
    }
}
