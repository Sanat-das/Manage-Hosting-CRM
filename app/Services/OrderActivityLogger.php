<?php

namespace App\Services;

use App\Models\Order;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;

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

        self::write($order, AuditEvent::OrderCreated, "Order {$order->order_number} created for {$productName} ({$order->billing_cycle}, qty {$order->quantity})", array_merge([
            'amount' => (float) $order->total,
            'cycle' => $order->billing_cycle,
        ], $metadata));
    }

    /**
     * Write an `order_status_changed` row (from/to/by captured in metadata).
     */
    public static function changed(Order $order, string $from, string $to, ?string $by = null): void
    {
        self::write($order, AuditEvent::OrderStatusChanged, "Order status changed from '{$from}' to '{$to}'", [
            'from' => $from,
            'to' => $to,
            'by' => $by,
        ]);
    }

    private static function write(Order $order, AuditEvent|string $action, string $description, array $metadata = []): void
    {
        app(AuditRecorder::class)->activity(
            $action,
            $order->customer,
            array_merge(['order_id' => $order->id, 'order_number' => $order->order_number], $metadata),
            $description,
        );
    }
}
