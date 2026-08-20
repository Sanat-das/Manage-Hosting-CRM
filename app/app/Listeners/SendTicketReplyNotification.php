<?php

namespace App\Listeners;

use App\Events\TicketReply;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\TicketReplyNotification;
use App\Services\NotificationPreferenceService;

/**
 * Notify the counterparty when a support ticket receives a reply.
 *
 * A staff reply notifies the ticket's customer; a customer reply notifies
 * admin users. Gated through NotificationPreferenceService (type "ticket.reply").
 */
class SendTicketReplyNotification
{
    public function __construct(private readonly NotificationPreferenceService $prefs) {}

    public function handle(TicketReply $event): void
    {
        $ticket = $event->ticket->fresh(['customer']);
        $byStaff = (bool) $event->reply->is_staff;

        if ($byStaff) {
            $customer = $ticket->customer;

            if (! $customer instanceof Customer) {
                return;
            }

            if ($this->prefs->isEnabled($customer, 'ticket.reply')) {
                $customer->notify(new TicketReplyNotification($ticket, true));
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
