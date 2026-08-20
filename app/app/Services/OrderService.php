<?php

namespace App\Services;

use App\Events\OrderCreated;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 *
 * Activation side-effects live here too: a pending→active hop seeds the
 * recurring-billing schedule (next_billing_date) and fires OrderCreated (the
 * provisioning trigger stub), so every caller — admin UI, API, jobs — gets
 * the same lifecycle behavior.
 */
class OrderService
{
    public function __construct(private readonly HostingService $hosting) {}
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
     * Provisioning modules that auto-provision on invoice payment. Anything
     * else (the 'manual' module) lands the order in 'provisioning' for admin
     * handling. Mirrors Product::PROVISIONING_MODULES minus 'manual'.
     */
    public const AUTO_PROVISION_MODULES = ['cpanel', 'plesk', 'directadmin', 'virtualizor', 'custom'];

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

            if (in_array($from, [Order::STATUS_PENDING, Order::STATUS_PROVISIONING, Order::STATUS_FAILED], true) && $to === Order::STATUS_ACTIVE) {
                // Per-service recurring schedule (WHMCS model): each order item
                // (the purchased product/service) renews on ITS OWN billing
                // cycle, so activation seeds every recurring item's next
                // billing date. The order's next_billing_date is kept purely as
                // a summary — the earliest item date — for the orders list.
                // `failed -> active` is the manual retry path: the first
                // activation attempt rolled back, so re-seeding is required
                // here just as much as on a fresh activation.
                $nextDates = [];
                foreach ($order->items as $item) {
                    $cycle = $item->billing_cycle ?? $order->billing_cycle;
                    $next = $this->nextBillingDate($cycle);

                    if ($next !== null) {
                        $item->update([
                            'next_billing_date' => $next,
                            'billing_cycles_count' => $item->billing_cycles_count ?? 1,
                        ]);
                        $nextDates[] = $next;
                    }
                }

                if ($order->items->isEmpty()) {
                    // Legacy order-level activation (orders created before the
                    // per-item billing columns): keep the order's own schedule.
                    $order->next_billing_date = $this->nextBillingDate($order->billing_cycle);
                } else {
                    $order->next_billing_date = $nextDates !== [] ? min($nextDates) : null;
                }

                // Provision: create the hosting account (when the product
                // is a hosting service) and lease the IPs the product's flags
                // declare when available. IP leasing is best-effort — an
                // exhausted IPAM pool never blocks activation; IPs are
                // assigned later from the hosting page. On a failed->active
                // retry the earlier attempt rolled back, so re-provisioning
                // is exactly what the retry must do.
                $this->hosting->provisionFromOrder($order);
            }

            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_user_id' => auth()->id(),
                'notes' => $notes,
            ]);
        });

        $order = $order->refresh();

        if ($from === Order::STATUS_PENDING && $to === Order::STATUS_ACTIVE) {
            OrderCreated::dispatch($order);
        }

        if ($to === Order::STATUS_PAID) {
            OrderPaid::dispatch($order);
        }

        return $order;
    }

    /**
     * Next billing date for a cycle; null for one-time orders (never re-billed).
     */
    private function nextBillingDate(string $cycle): ?string
    {
        $months = Order::CYCLE_MONTHS[$cycle] ?? 1;

        return $months > 0 ? now()->addMonths($months)->toDateString() : null;
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

    /**
     * Advance the order after its invoice is fully paid, following the
     * product's provisioning module:
     *
     *  - 'manual' (and any unrecognized value) → the service awaits manual
     *    provisioning: the order moves paid → provisioning, where an admin
     *    completes it through the existing activate flow.
     *  - automated modules (see AUTO_PROVISION_MODULES) → auto-provision:
     *    paid → provisioning → active, creating the hosting account (and
     *    leasing IPs when available) along the way; IP leasing is
     *    best-effort and never blocks activation. A genuine failure lands
     *    the order in 'failed' instead.
     *
     * Call with a pending/paid order after markPaid(). Idempotent for
     * orders already past the paid stage.
     */
    public function advanceAfterPayment(Order $order): Order
    {
        $module = $order->product?->provisioning_module ?? 'manual';

        if (in_array($module, self::AUTO_PROVISION_MODULES, true)) {
            try {
                $this->transition($order, Order::STATUS_PROVISIONING, 'Auto-provisioning after invoice payment');

                return $this->transition($order, Order::STATUS_ACTIVE, 'Auto-provisioned on invoice payment');
            } catch (\Throwable $e) {
                Log::error('Auto-provisioning failed after invoice payment', [
                    'order_id' => $order->id,
                    'module' => $module,
                    'error' => $e->getMessage(),
                ]);

                try {
                    // The failed activation rolled back, so the in-memory
                    // status is stale — refresh before marking failed.
                    return $this->transition($order->refresh(), Order::STATUS_FAILED, 'Auto-provisioning failed: '.$e->getMessage());
                } catch (\Throwable $e2) {
                    Log::warning('Order left in provisioning after failed auto-provisioning', [
                        'order_id' => $order->id,
                        'error' => $e2->getMessage(),
                    ]);

                    return $order->refresh();
                }
            }
        }

        return $this->transition($order, Order::STATUS_PROVISIONING, 'Awaiting manual provisioning after invoice payment');
    }
}
