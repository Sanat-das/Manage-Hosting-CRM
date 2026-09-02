<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification: a support ticket was transferred to another department.
 */
class TicketTransferredNotification extends Notification
{
    use Queueable;

    public function __construct(public Ticket $ticket, public string $fromDepartment, public string $toDepartment) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'type' => 'ticket.transferred',
            'ticket_id' => $this->ticket->id,
            'ticket_no' => $this->ticket->ticket_no,
            'subject' => $this->ticket->subject,
            'from_department' => $this->fromDepartment,
            'to_department' => $this->toDepartment,
            'message' => "Ticket {$this->ticket->ticket_no} was transferred to {$this->toDepartment}.",
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable)->data;
    }
}
