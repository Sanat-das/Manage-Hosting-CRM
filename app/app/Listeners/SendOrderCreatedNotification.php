<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\OrderCreatedNotification;
use App\Services\NotificationPreferenceService;

/**
 * Notify the customer (and admin users) when an order is activated.
 *
 * Mirrors SendOrderPaidNotification: the Customer model is the aggregate that
 * owns the order and is the database-channel notifiable target; admin users
 * are notified too. Every target is gated through NotificationPreferenceService.
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

        if ($this->prefs->isEnabled($customer, 'order.created')) {
            $customer->notify(new OrderCreatedNotification($order));
        }

        $this->notifyAdmins($order);
    }

    private function notifyAdmins($order): void
    {
        User::query()
            ->where('role', 'admin')
            ->get()
            ->each(function (User $admin) use ($order) {
                if ($this->prefs->isEnabled($admin, 'order.created')) {
                    $admin->notify(new OrderCreatedNotification($order));
                }
            });
    }
}
