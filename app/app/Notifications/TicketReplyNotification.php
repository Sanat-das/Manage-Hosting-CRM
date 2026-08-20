<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification: a support ticket received a reply.
 */
class TicketReplyNotification extends Notification
{
    use Queueable;

    public function __construct(public Ticket $ticket, public bool $byStaff) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'type' => 'ticket.reply',
            'ticket_id' => $this->ticket->id,
            'ticket_no' => $this->ticket->ticket_no,
            'subject' => $this->ticket->subject,
            'by_staff' => $this->byStaff,
            'message' => "Ticket {$this->ticket->ticket_no} received a reply.",
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable)->data;
    }
}
