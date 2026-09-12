<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone started or stopped typing.
 *
 * Carries no message text at all — a keystroke-level feed of what a colleague
 * is part-way through writing is not something the wire needs to see.
 */
class TypingIndicator implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $conversationId,
        public int $userId,
        public string $userName,
        public bool $typing,
    ) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('chat.typing.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'chat.typing';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'typing' => $this->typing,
        ];
    }
}
