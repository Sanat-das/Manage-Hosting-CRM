<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ChatConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class ChatAssignmentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ChatConversation $conversation,
        public int $actorId,
        public string $actorName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage($this->payload());
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function broadcastType(): string
    {
        return 'chat.assign';
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'type' => 'chat.assign',
            'conversation_id' => $this->conversation->id,
            'conversation_name' => $this->conversation->displayName(),
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'url' => '/admin/chat?c='.$this->conversation->id,
        ];
    }
}
