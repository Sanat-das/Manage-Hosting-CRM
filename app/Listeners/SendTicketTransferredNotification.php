<?php

namespace App\Listeners;

use App\Events\TicketTransferred;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Notifications\TicketTransferredNotification;
use App\Services\NotificationPreferenceService;

/**
 * Notify staff when a support ticket is transferred to another department.
 *
 * Notifies staff members of the target department (via the
 * ticket_department_user pivot) plus admin users. Gated through
 * NotificationPreferenceService (type "ticket.transfer"). Database channel
 * only — a transfer is an internal note, so the customer is never notified.
 */
class SendTicketTransferredNotification
{
    public function __construct(
        private readonly NotificationPreferenceService $prefs,
    ) {}

    public function handle(TicketTransferred $event): void
    {
        $department = TicketDepartment::where('slug', $event->toDepartment)->first();

        $departmentRecipients = ($department?->staff ?? collect())
            ->filter(fn (User $member) => $member->status === 'active');

        $admins = User::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->where('role', 'admin')
                ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'admin')))
            ->get();

        $departmentRecipients->concat($admins)->unique('id')->each(function (User $user) use ($event) {
            if ($this->prefs->isEnabled($user, 'ticket.transfer', 'database')) {
                $user->notify(new TicketTransferredNotification($event->ticket, $event->fromDepartment, $event->toDepartment));
            }
        });
    }
}
