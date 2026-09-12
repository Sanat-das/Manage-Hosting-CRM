<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Someone @mentioned you in a chat message.
 *
 * Dual-channel: `database` (so it appears in the notification bell) and
 * `broadcast` (so the bell lights up without a poll). The broadcast lands on
 * the standard Laravel private channel `App.Models.User.{id}` — do not invent a
 * new channel name.
 *
 * Queued on the default queue. Do NOT call onQueue() and do NOT set $queue:
 * this install only drains `emails` and `default` via a once-a-minute scheduled
 * `queue:work`. Any other queue piles up in `jobs` forever.
 */
class ChatMentionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ChatConversationMessage $message,
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
        return 'chat.mention';
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'type' => 'chat.mention',
            'message_id' => $this->message->id,
            'conversation_id' => $this->conversation->id,
            'conversation_name' => $this->conversation->displayName(),
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            // Escaped at write time so a <script> in the body never survives as
            // markup even if a future renderer forgets to escape. Keep it short:
            // the notifications table is not a message archive.
            'excerpt' => htmlspecialchars(mb_substr((string) $this->message->body, 0, 200), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'url' => '/admin/chat?c='.$this->conversation->id.'#message-'.$this->message->id,
        ];
    }
}
