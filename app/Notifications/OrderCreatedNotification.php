<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification: an order entered the system.
 *
 * Raised both when an order is activated (pending -> active) and when a
 * customer places one from the storefront, which lands pending awaiting
 * payment. The message is derived from the order's actual status rather than
 * assuming activation — a storefront order that reads "has been activated"
 * tells the customer their unpaid service is live.
 */
class OrderCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'type' => 'order.created',
            'order_id' => $this->order->id,
            'order_no' => $this->order->order_no,
            'total' => $this->order->total,
            'status' => $this->order->status,
            'message' => $this->message(),
        ]);
    }

    private function message(): string
    {
        $orderNo = $this->order->order_no;

        return match ($this->order->status) {
            Order::STATUS_ACTIVE => "Order {$orderNo} has been activated.",
            Order::STATUS_PENDING => "Order {$orderNo} has been placed and is awaiting payment.",
            default => "Order {$orderNo} has been placed (".str_replace('_', ' ', (string) $this->order->status).').',
        };
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable)->data;
    }
}
