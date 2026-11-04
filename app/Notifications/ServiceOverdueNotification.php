<?php

namespace App\Notifications;

use App\Models\HostingAccount;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification: a service passed its renewal due date unpaid.
 *
 * Raised by HostingOverdueCheckCommand for every active staff user, so the
 * admin/staff/support inbox learns about a hosting account that lapsed past
 * its renewal date — the one case the renewal-reminder chain silently drops.
 * Nothing is queued — the caller gates the dispatch per account per due date
 * through the cache.
 */
class ServiceOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(public HostingAccount $account, public Carbon $dueDate) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $dueDate = $this->dueDate->toDateString();
        $hostName = (string) $this->account->host_name;
        $customerName = $this->account->customer?->full_name ?? '';

        return new DatabaseMessage([
            'type' => 'service.overdue',
            'hosting_account_id' => $this->account->id,
            'order_no' => $this->account->order?->order_number,
            'host_name' => $hostName,
            'customer_name' => $customerName,
            'due_date' => $dueDate,
            'message' => "Service {$hostName} ({$customerName}) is overdue — due {$dueDate}.",
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable)->data;
    }
}
