<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification: a service renews soon.
 *
 * Raised by RenewalReminderService::remind() alongside the renewal reminder
 * email, so a client who opted out of the email still sees the renewal in the
 * portal inbox. Nothing is queued — the caller (the renewals:remind command)
 * gates the dispatch per user through NotificationPreferenceService.
 */
class ServiceRenewalReminderNotification extends Notification
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $renewalDate = $this->order->next_billing_date?->toDateString();

        return new DatabaseMessage([
            'type' => 'renewal.upcoming',
            'order_id' => $this->order->id,
            'order_no' => $this->order->order_no,
            'renewal_date' => $renewalDate,
            'message' => $renewalDate === null
                ? "Order {$this->order->order_no} is due for renewal."
                : "Order {$this->order->order_no} renews on {$renewalDate}.",
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable)->data;
    }
}
