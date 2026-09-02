<?php

namespace App\Listeners;

use App\Events\TicketReply;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\TicketReplyNotification;
use App\Services\NotificationPreferenceService;
use App\Services\TicketMailService;

/**
 * Notify the counterparty when a support ticket receives a reply.
 *
 * A staff reply notifies the ticket's customer; a customer reply notifies
 * admin users. Gated through NotificationPreferenceService (type "ticket.reply").
 *
 * Only the staff branch sends mail. Staff work the tickets in the admin UI and
 * get the in-app notification there, and emailing them would put our own
 * outbound message back into the mailbox the fetch command reads.
 *
 * A guest ticket (no linked customer) has no notifiable model to check a
 * per-customer preference row against or to send the in-app notification to,
 * so a staff reply on one just emails the guest address unconditionally —
 * "ticket.reply" has no global settings key to fall back to either, so this
 * matches the effective default `isEnabled()` would already return.
 */
class SendTicketReplyNotification
{
    public function __construct(
        private readonly NotificationPreferenceService $prefs,
        private readonly TicketMailService $mail,
    ) {}

    public function handle(TicketReply $event): void
    {
        $ticket = $event->ticket->fresh(['customer']);
        $byStaff = (bool) $event->reply->is_staff;

        if ($byStaff) {
            $customer = $ticket->customer;

            if (! $customer instanceof Customer) {
                $this->mail->sendReply($ticket, $event->reply);

                return;
            }

            if ($this->prefs->isEnabled($customer, 'ticket.reply')) {
                $customer->notify(new TicketReplyNotification($ticket, true));
                $this->mail->sendReply($ticket, $event->reply);
            }

            return;
        }

        // Customer replied → notify admin users.
        User::query()
            ->where('role', 'admin')
            ->get()
            ->each(function (User $admin) use ($ticket) {
                if ($this->prefs->isEnabled($admin, 'ticket.reply')) {
                    $admin->notify(new TicketReplyNotification($ticket, false));
                }
            });
    }
}
