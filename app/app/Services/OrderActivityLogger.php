<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Order;

/**
 * Write the customer-facing ActivityLog rows for order lifecycle events.
 *
 * Centralizes the activity-log creation every order entry point used to
 * duplicate (or skip): the admin order form, the admin cart, the client
 * storefront and the orders REST API all call created()/changed() so each
 * entry point writes the exact same rows — the audit trail surfaced on the
 * customer page. The per-order lifecycle audit (state hops) stays in
 * order_status_history (written by OrderService); these rows are the
 * human-readable trail.
 */
class OrderActivityLogger
{
    /**
     * Write an `order_created` row (amount + cycle captured in metadata).
     */
    public static function created(Order $order, array $metadata = []): void
    {
        $productName = $order->product?->name ?? 'Order';

        self::write($order, 'order_created', "Order {$order->order_number} created for {$productName} ({$order->billing_cycle}, qty {$order->quantity})", array_merge([
            'amount' => (float) $order->total,
            'cycle' => $order->billing_cycle,
        ], $metadata));
    }

    /**
     * Write an `order_status_changed` row (from/to/by captured in metadata).
     */
    public static function changed(Order $order, string $from, string $to, ?string $by = null): void
    {
        self::write($order, 'order_status_changed', "Order status changed from '{$from}' to '{$to}'", [
            'from' => $from,
            'to' => $to,
            'by' => $by,
        ]);
    }

    private static function write(Order $order, string $action, string $description, array $metadata = []): void
    {
        ActivityLog::create([
            'customer_id' => $order->customer_id,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'metadata' => array_merge(['order_id' => $order->id, 'order_number' => $order->order_number], $metadata),
            'ip_address' => request()->ip(),
        ]);
    }
}
