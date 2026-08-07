<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Order lifecycle service (gap-fillup T1.2).
 *
 * Owns the guarded order state machine. Mirrors DomainService's transition
 * idiom: a TRANSITIONS map, idempotent same-state short-circuit, InvalidArgument
 * on illegal moves, and an immutable status-history audit row per hop.
 *
 * The map is ADDITIVE over the pre-existing controller transitions so the
 * legacy admin paths (pending→active direct activation, active→suspended)
 * keep working while the richer paid/provisioning/failed track is added.
 */
class OrderService
{
    /**
     * State machine: source status => allowed destination statuses.
     * Terminal states (cancelled / terminated) have no outgoing edges.
     */
    private const TRANSITIONS = [
        Order::STATUS_PENDING => [Order::STATUS_PAID, Order::STATUS_ACTIVE, Order::STATUS_CANCELLED],
        Order::STATUS_PAID => [Order::STATUS_PROVISIONING, Order::STATUS_CANCELLED],
        Order::STATUS_PROVISIONING => [Order::STATUS_ACTIVE, Order::STATUS_FAILED, Order::STATUS_CANCELLED],
        Order::STATUS_FAILED => [Order::STATUS_ACTIVE, Order::STATUS_CANCELLED],
        Order::STATUS_ACTIVE => [Order::STATUS_SUSPENDED, Order::STATUS_CANCELLED, Order::STATUS_TERMINATED],
        Order::STATUS_SUSPENDED => [Order::STATUS_ACTIVE, Order::STATUS_CANCELLED, Order::STATUS_TERMINATED],
        Order::STATUS_CANCELLED => [],
        Order::STATUS_TERMINATED => [],
    ];

    /**
     * Apply a guarded status transition and persist an audit row.
     *
     * @throws InvalidArgumentException when the transition is not allowed
     */
    public function transition(Order $order, string $to, ?string $notes = null): Order
    {
        $to = strtolower(trim($to));

        if (! in_array($to, Order::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown order status: {$to}");
        }

        if ($order->status === $to) {
            return $order; // idempotent — no audit row
        }

        if (! $this->canTransition($order, $to)) {
            throw new InvalidArgumentException(
                "Order cannot move from '{$order->status}' to '{$to}'."
            );
        }

        $from = $order->status;

        DB::transaction(function () use ($order, $from, $to, $notes) {
            $order->status = $to;
            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_user_id' => auth()->id(),
                'notes' => $notes,
            ]);
        });

        return $order->refresh();
    }

    /**
     * Whether the state machine allows the given transition (no side effects).
     */
    public function canTransition(Order $order, string $to): bool
    {
        return in_array(strtolower(trim($to)), self::TRANSITIONS[$order->status] ?? [], true);
    }

    // ─────────────────────────── Named helpers ───────────────────────────

    public function markPaid(Order $order, ?string $notes = null): Order
    {
        return $this->transition($order, Order::STATUS_PAID, $notes);
    }

    public function markProvisioning(Order $order, ?string $notes = null): Order
    {
        return $this->transition($order, Order::STATUS_PROVISIONING, $notes);
    }

    public function fail(Order $order, ?string $notes = null): Order
    {
        return $this->transition($order, Order::STATUS_FAILED, $notes);
    }

    public function activate(Order $order, ?string $notes = null): Order
    {
        return $this->transition($order, Order::STATUS_ACTIVE, $notes);
    }

    public function suspend(Order $order, ?string $notes = null): Order
    {
        return $this->transition($order, Order::STATUS_SUSPENDED, $notes);
    }

    public function cancel(Order $order, ?string $notes = null): Order
    {
        return $this->transition($order, Order::STATUS_CANCELLED, $notes);
    }

    public function terminate(Order $order, ?string $notes = null): Order
    {
        return $this->transition($order, Order::STATUS_TERMINATED, $notes);
    }
}
