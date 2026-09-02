<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketReply as TicketReplyModel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a reply is added to a support ticket.
 *
 * The counterparty is whoever did NOT write the reply: a staff reply notifies
 * the ticket's customer; a customer reply notifies admin users.
 *
 * The model import is aliased on purpose: this event and App\Models\TicketReply
 * share a short name, and importing it unaliased made PHP fatal with "Cannot
 * redeclare class App\Events\TicketReply (previously declared as local import)"
 * the moment the file was loaded — i.e. on every reply.
 */
class TicketReply
{
    use Dispatchable, SerializesModels;

    public function __construct(public Ticket $ticket, public TicketReplyModel $reply) {}
}
