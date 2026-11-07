<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\OrderCreatedNotification;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\NotificationMatrix;

/**
 * Notify the customer (and matrix-enabled staff) when an order is activated or placed.
 *
 * Mirrors SendOrderPaidNotification: the Customer model is the aggregate that
 * owns the order and is the database-channel notifiable target; staff are notified
 * for the notification_event_settings-enabled roles. Every target is gated through
 * NotificationPreferenceService.
 *
 * The notification wording follows the order's status, so the same listener
 * serves both an activation and a pending storefront order.
 */
class SendOrderCreatedNotification
{
    public function __construct(private readonly NotificationPreferenceService $prefs) {}

    public function handle(OrderCreated $event): void
    {
        $order = $event->order->fresh(['customer']);
        $customer = $order->customer;

        if (! $customer instanceof Customer) {
            return;
        }

        if ($this->prefs->isEnabled($customer, 'order.created', 'database')) {
            $customer->notify(new OrderCreatedNotification($order));
        }

        $this->notifyAdmins($order);
    }

    private function notifyAdmins($order): void
    {
        NotificationMatrix::staffPool('order.created', 'database')
            ->each(function (User $staff) use ($order) {
                if ($this->prefs->isEnabled($staff, 'order.created', 'database')) {
                    $staff->notify(new OrderCreatedNotification($order));
                }
            });
    }
}
