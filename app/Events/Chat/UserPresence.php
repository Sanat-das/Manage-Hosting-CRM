<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A panel user came online or went away.
 *
 * The presence channel's own member list is the source of truth for who is
 * connected; this event exists for the transitions the client wants to react to
 * (the sidebar dot) without re-reading the whole roster.
 */
class UserPresence implements ShouldBroadcastNow
{
    use Dispatchable;

    public const ONLINE = 'online';

    public const OFFLINE = 'offline';

    public function __construct(
        public int $userId,
        public string $userName,
        public string $status = self::ONLINE,
    ) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('chat.presence')];
    }

    public function broadcastAs(): string
    {
        return 'chat.presence';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'status' => $this->status,
        ];
    }
}
