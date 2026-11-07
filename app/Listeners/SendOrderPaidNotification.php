<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\OrderPaidNotification;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\NotificationMatrix;

/**
 * Notify the customer (and matrix-enabled staff) when an order is paid.
 *
 * The Customer model is Notifiable directly and is the aggregate that owns
 * the order, so it is the database-channel notifiable target. Staff are notified
 * for the notification_event_settings-enabled roles. Every target is gated through
 * NotificationPreferenceService.
 */
class SendOrderPaidNotification
{
    public function __construct(private readonly NotificationPreferenceService $prefs) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order->fresh(['customer']);
        $customer = $order->customer;

        if (! $customer instanceof Customer) {
            return;
        }

        if ($this->prefs->isEnabled($customer, 'order.paid', 'database')) {
            $customer->notify(new OrderPaidNotification($order));
        }

        $this->notifyAdmins($order);
    }

    private function notifyAdmins($order): void
    {
        NotificationMatrix::staffPool('order.paid', 'database')
            ->each(function (User $staff) use ($order) {
                if ($this->prefs->isEnabled($staff, 'order.paid', 'database')) {
                    $staff->notify(new OrderPaidNotification($order));
                }
            });
    }
}
