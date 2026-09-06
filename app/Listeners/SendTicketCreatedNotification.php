<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Services\NotificationPreferenceService;
use App\Services\TicketMailService;
use App\Services\TicketService;
use App\Support\AppSettings;

/**
 * Notify the customer when a support ticket is created.
 *
 * Both sides hear about it: the customer gets the acknowledgement, and the
 * staff who own the department get an in-app notification so a ticket opened
 * from the portal or from an inbound email does not sit unseen. Gated through
 * NotificationPreferenceService (type "ticket.new").
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
        private readonly TicketService $tickets,
    ) {}

    public function handle(TicketCreated $event): void
    {
        $ticket = $event->ticket->fresh(['customer']);
        $customer = $ticket->customer;

        $this->notifyStaff($ticket);

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

    /**
     * Tell the desk a ticket landed on that it has work.
     *
     * Nothing did this before: a customer opening a ticket in the portal, or
     * one auto-opened from an inbound email, produced no staff-facing signal
     * whatsoever — the only way to find it was to go looking at the list.
     *
     * In-app only, deliberately. Mailing staff would put our own message into
     * the mailbox `tickets:fetch-mail` polls, and they are already in the admin
     * UI where the notification shows.
     *
     * The opening reply's author is excluded, so an admin raising a ticket on a
     * customer's behalf is not notified about their own ticket.
     */
    private function notifyStaff(Ticket $ticket): void
    {
        $openedBy = $ticket->replies()->orderBy('id')->value('user_id');

        $this->tickets
            ->staffRecipientsFor($ticket, $openedBy !== null ? (int) $openedBy : null)
            ->each(function (User $staff) use ($ticket) {
                if ($this->prefs->isEnabled($staff, 'ticket.new')) {
                    $staff->notify(new TicketCreatedNotification($ticket));
                }
            });
    }
}
