<?php

namespace App\Listeners;

use App\Events\TicketReply;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\TicketReplyNotification;
use App\Services\NotificationPreferenceService;
use App\Services\TicketMailService;
use App\Services\TicketService;

/**
 * Notify the counterparty when a support ticket receives a reply.
 *
 * A staff reply notifies the ticket's customer; a customer reply notifies the
 * staff who own the ticket. Gated through NotificationPreferenceService
 * (type "ticket.reply").
 *
 * "Who owns the ticket" is {@see TicketService::staffRecipientsFor()}: the
 * assignee and the ticket's department, falling back to admins only when
 * neither exists. This used to notify `role = 'admin'` and nothing else, so
 * the person actually holding the ticket — and every non-admin member of the
 * department that owns it — was never told the customer had answered.
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
        private readonly TicketService $tickets,
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

        // Customer replied → notify the staff who own this ticket. The author
        // is excluded: a staff member filing a reply on a customer's behalf
        // does not need telling about it.
        $this->tickets
            ->staffRecipientsFor($ticket, $event->reply->user_id)
            ->each(function (User $staff) use ($ticket) {
                if ($this->prefs->isEnabled($staff, 'ticket.reply')) {
                    $staff->notify(new TicketReplyNotification($ticket, false));
                }
            });
    }
}
