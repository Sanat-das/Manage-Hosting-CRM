<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Models\Customer;
use App\Notifications\TicketCreatedNotification;
use App\Services\NotificationPreferenceService;

/**
 * Notify the customer when a support ticket is created.
 *
 * An admin opens the ticket on the customer's behalf, so the customer is the
 * counterparty. Gated through NotificationPreferenceService (type "ticket.new").
 */
class SendTicketCreatedNotification
{
    public function __construct(private readonly NotificationPreferenceService $prefs) {}

    public function handle(TicketCreated $event): void
    {
        $ticket = $event->ticket->fresh(['customer']);
        $customer = $ticket->customer;

        if (! $customer instanceof Customer) {
            return;
        }

        if ($this->prefs->isEnabled($customer, 'ticket.new')) {
            $customer->notify(new TicketCreatedNotification($ticket));
        }
    }
}
