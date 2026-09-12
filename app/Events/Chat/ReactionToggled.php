<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reaction was added to, or taken off, a message.
 *
 * One event for both directions rather than a separate ReactionAdded: reacting
 * is a toggle, and a client that only ever hears about additions ends up
 * showing reactions that no longer exist until the page is reloaded.
 */
class ReactionToggled implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $messageId,
        public int $conversationId,
        public int $userId,
        public string $emoji,
        public bool $added,
        public int $count,
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
        return 'chat.reaction.toggled';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'user_id' => $this->userId,
            'emoji' => $this->emoji,
            'added' => $this->added,
            'count' => $this->count,
        ];
    }
}
