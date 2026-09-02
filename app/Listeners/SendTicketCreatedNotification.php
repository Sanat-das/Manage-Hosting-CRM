<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Models\Customer;
use App\Notifications\TicketCreatedNotification;
use App\Services\NotificationPreferenceService;
use App\Services\TicketMailService;
use App\Support\AppSettings;

/**
 * Notify the customer when a support ticket is created.
 *
 * An admin opens the ticket on the customer's behalf, so the customer is the
 * counterparty. Gated through NotificationPreferenceService (type "ticket.new").
 *
 * The in-app notification and the email are two channels of the same decision,
 * so one preference check covers both. The email additionally carries the
 * `[TKT-…]` tag and a Message-ID (TicketMailService), which is what lets the
 * customer's reply come back into this ticket.
 *
 * A guest ticket (no linked customer) has no notifiable model to check a
 * per-customer preference row against — an unauthenticated visitor cannot log
 * in to set one — so it only gets the email, gated on the global
 * `notify_new_tickets` setting instead of a per-customer row.
 */
class SendTicketCreatedNotification
{
    public function __construct(
        private readonly NotificationPreferenceService $prefs,
        private readonly TicketMailService $mail,
    ) {}

    public function handle(TicketCreated $event): void
    {
        $ticket = $event->ticket->fresh(['customer']);
        $customer = $ticket->customer;

        if ($customer instanceof Customer) {
            if ($this->prefs->isEnabled($customer, 'ticket.new')) {
                $customer->notify(new TicketCreatedNotification($ticket));
                $this->mail->sendCreated($ticket);
            }

            return;
        }

        if (AppSettings::bool('notify_new_tickets', true)) {
            $this->mail->sendCreated($ticket);
        }
    }
}
