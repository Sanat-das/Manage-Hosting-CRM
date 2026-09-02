<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\OrderPaidNotification;
use App\Services\NotificationPreferenceService;

/**
 * Notify the customer (and admin users) when an order is paid.
 *
 * The Customer model is Notifiable directly and is the aggregate that owns
 * the order, so it is the database-channel notifiable target. Admin users are
 * notified too. Every target is gated through NotificationPreferenceService.
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

        if ($this->prefs->isEnabled($customer, 'order.paid')) {
            $customer->notify(new OrderPaidNotification($order));
        }

        $this->notifyAdmins($order);
    }

    private function notifyAdmins($order): void
    {
        User::query()
            ->where('role', 'admin')
            ->get()
            ->each(function (User $admin) use ($order) {
                if ($this->prefs->isEnabled($admin, 'order.paid')) {
                    $admin->notify(new OrderPaidNotification($order));
                }
            });
    }
}
