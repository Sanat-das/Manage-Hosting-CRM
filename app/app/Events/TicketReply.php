<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a reply is added to a support ticket.
 *
 * The counterparty is whoever did NOT write the reply: a staff reply notifies
 * the ticket's customer; a customer reply notifies admin users.
 */
class TicketReply
{
    use Dispatchable, SerializesModels;

    public function __construct(public Ticket $ticket, public TicketReply $reply) {}
}
