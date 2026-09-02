<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a support ticket is created.
 *
 * The counterparty is the ticket's customer user: an admin opened the ticket
 * on the customer's behalf, so the customer is notified.
 */
class TicketCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Ticket $ticket) {}
}
