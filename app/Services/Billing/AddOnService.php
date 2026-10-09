<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * AddOnService — order-time add-on expansion (WHMCS-style).
 *
 * Each selected add-on becomes its own billable `order_items` row — on the
 * parent service's cycle when the add-on's pricing matrix prices that cycle,
 * otherwise on the add-on's own cycle; a setup fee becomes its own `one_time`
 * row. Renewal needs no new engine:
 * BillingService::processRecurringBilling already iterates per item.
 */
final class AddOnService
{
    public function __construct(private readonly BillingService $billing) {}

    /** Active add-ons orderable with this product (product-scoped + global). */
    public function applicableFor(Product $product): Collection
    {
        return ProductAddon::active()->applicableTo($product->id)->orderBy('name')->get();
    }

    /** Add-on purchase rows hanging off one parent service line. */
    public function forParent(OrderItem $parentItem): Collection
    {
        return OrderItem::where('parent_item_id', $parentItem->id)->get();
    }

    /**
     * WHMCS rule: an add-on bought with a product uses the PARENT's cycle when the add-on is
     * priced for that cycle; otherwise its own default cycle/price.
     *
     * @return array{cycle: string, unit_price: float, setup_fee: float}
     */
    public function resolveCycleAndPrice(ProductAddon $addon, string $parentCycle): array
    {
        $row = $addon->priceForCycle($parentCycle);

        if ($row !== null) {
            return [
                'cycle' => $parentCycle,
                'unit_price' => (float) $row->price,
                'setup_fee' => (float) $row->setup_fee,
            ];
        }

        return [
            'cycle' => $addon->billing_cycle,
            'unit_price' => (float) $addon->price,
            'setup_fee' => (float) $addon->setup_fee,
        ];
    }

    /** Display-only preview of the order_items materialize() would create. Never persists. Invalid selections are skipped. */
    public function previewLinesFor(Product $product, string $parentCycle, array $selections): array
    {
        $lines = [];

        foreach ($selections as $selection) {
            $addon = ProductAddon::find($selection['addon_id'] ?? null);

            if ($addon === null || $addon->status !== 'active') {
                continue;
            }

            if ($addon->product_id !== null && (int) $addon->product_id !== (int) $product->id) {
                continue;
            }

            $quantity = max(1, (int) ($selection['quantity'] ?? 1));

            $resolved = $this->resolveCycleAndPrice($addon, $parentCycle);
            $price = $resolved['unit_price'];

            $lines[] = [
                'product_name' => $addon->name,
                'billing_cycle' => $resolved['cycle'],
                'quantity' => $quantity,
                'unit_price' => $price,
                'total' => round($price * $quantity, 2),
                'setup_fee' => $resolved['setup_fee'],
                'preview_addon' => true,
            ];

            if ($resolved['setup_fee'] > 0) {
                $setupFee = $resolved['setup_fee'];

                $lines[] = [
                    'product_name' => "{$addon->name} — Setup Fee",
                    'billing_cycle' => 'one_time',
                    'quantity' => 1,
                    'unit_price' => $setupFee,
                    'total' => $setupFee,
                    'setup_fee' => $setupFee,
                    'preview_addon' => true,
                ];
            }
        }

        return $lines;
    }

    /**
     * Order-time expansion.
     *
     * @param  array<int, array{addon_id:int, quantity:int}>  $selections
     * @return array<int, OrderItem> recurring + setup rows
     */
    public function materialize(Order $order, OrderItem $parentItem, array $selections): array
    {
        if ((int) $parentItem->order_id !== (int) $order->id) {
            throw new InvalidArgumentException('Parent item does not belong to the given order.');
        }

        if ($parentItem->product_addon_id !== null) {
            throw new InvalidArgumentException('Add-ons cannot be attached to an add-on line.');
        }

        $rows = [];

        foreach ($selections as $selection) {
            $addon = ProductAddon::find($selection['addon_id'] ?? null);

            if ($addon === null) {
                throw new InvalidArgumentException('Unknown add-on.');
            }

            if ($addon->status !== 'active') {
                throw new InvalidArgumentException('Add-on is not active.');
            }

            if ($addon->product_id !== null && (int) $addon->product_id !== (int) $parentItem->product_id) {
                throw new InvalidArgumentException('Add-on is not applicable to this product.');
            }

            $quantity = max(1, (int) ($selection['quantity'] ?? 1));

            $resolved = $this->resolveCycleAndPrice(
                $addon,
                (string) ($parentItem->billing_cycle ?? $order->billing_cycle ?? 'monthly')
            );
            $price = $resolved['unit_price'];

            $rows[] = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $parentItem->product_id,
                'product_name' => $addon->name,
                'parent_item_id' => $parentItem->id,
                'product_addon_id' => $addon->id,
                'billing_cycle' => $resolved['cycle'],
                'quantity' => $quantity,
                'unit_price' => $price,
                'total' => round($price * $quantity, 2),
                'recurring_cycles_limit' => 0,
                'next_billing_date' => null,
                'billing_cycles_count' => (Order::CYCLE_MONTHS[$resolved['cycle']] ?? 0) > 0 ? 1 : 0,
            ]);

            if ($resolved['setup_fee'] > 0) {
                $setupFee = $resolved['setup_fee'];

                $rows[] = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $parentItem->product_id,
                    'product_name' => "{$addon->name} — Setup Fee",
                    'parent_item_id' => $parentItem->id,
                    'product_addon_id' => $addon->id,
                    'billing_cycle' => 'one_time',
                    'quantity' => 1,
                    'unit_price' => $setupFee,
                    'total' => $setupFee,
                    'recurring_cycles_limit' => 0,
                    'next_billing_date' => null,
                    'billing_cycles_count' => 0,
                ]);
            }
        }

        return $rows;
    }

    /**
     * Post-signup attach to an active (or suspended) order.
     *
     * WHMCS rule: a later purchase bills the CURRENT period immediately at the
     * add-on's OWN default cycle and price — no proration, no parent-cycle
     * matrix lookup (unlike order-time materialize()). The recurring row is
     * scheduled `today + cycle`; for `one_time` add-ons the schedule is null.
     * The setup fee, when present, becomes its own `one_time` row. Both are
     * invoiced at once (status sent, due in seven days) and the order's billing
     * summary is recomputed; an `activity_log` audit row records the attach.
     *
     * @param  User|null  $actor  The admin performing the attach, when there is one.
     */
    public function attach(Order $order, OrderItem $parentItem, ProductAddon $addon, int $quantity = 1, ?User $actor = null): OrderItem
    {
        if ((int) $parentItem->order_id !== (int) $order->id) {
            throw new InvalidArgumentException('Parent item does not belong to the given order.');
        }

        if ($parentItem->product_addon_id !== null) {
            throw new InvalidArgumentException('Add-ons cannot be attached to an add-on line.');
        }

        if ($addon->status !== 'active') {
            throw new InvalidArgumentException('Add-on is not active.');
        }

        if ($addon->product_id !== null && (int) $addon->product_id !== (int) $parentItem->product_id) {
            throw new InvalidArgumentException('Add-on is not applicable to this product.');
        }

        if (! in_array($order->status, [Order::STATUS_ACTIVE, Order::STATUS_SUSPENDED], true)) {
            throw new InvalidArgumentException('Add-ons can only be attached to an active or suspended order.');
        }

        foreach ($this->forParent($parentItem) as $existing) {
            if ($existing->product_addon_id === $addon->id && $existing->next_billing_date !== null) {
                throw new InvalidArgumentException('Add-on is already attached to this service.');
            }
        }

        $months = Order::CYCLE_MONTHS[$addon->billing_cycle] ?? 0;
        $quantity = max(1, $quantity);
        $price = (float) $addon->price;
        $setupFee = (float) $addon->setup_fee;
        $nextBillingDate = $months > 0
            ? CarbonImmutable::today('Asia/Kolkata')->addMonths($months)->toDateString()
            : null;
        $notes = "Add-on {$addon->name} attached to order {$order->order_number}";

        return DB::transaction(function () use ($order, $parentItem, $addon, $quantity, $months, $price, $setupFee, $nextBillingDate, $notes, $actor): OrderItem {
            $recurring = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $parentItem->product_id,
                'product_name' => $addon->name,
                'parent_item_id' => $parentItem->id,
                'product_addon_id' => $addon->id,
                'billing_cycle' => $addon->billing_cycle,
                'quantity' => $quantity,
                'unit_price' => $price,
                'total' => round($price * $quantity, 2),
                'recurring_cycles_limit' => 0,
                'next_billing_date' => $nextBillingDate,
                'billing_cycles_count' => $months > 0 ? 1 : 0,
            ]);

            $setup = null;

            if ($setupFee > 0) {
                $setup = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $parentItem->product_id,
                    'product_name' => "{$addon->name} — Setup Fee",
                    'parent_item_id' => $parentItem->id,
                    'product_addon_id' => $addon->id,
                    'billing_cycle' => 'one_time',
                    'quantity' => 1,
                    'unit_price' => $setupFee,
                    'total' => $setupFee,
                    'recurring_cycles_limit' => 0,
                    'next_billing_date' => null,
                    'billing_cycles_count' => 0,
                ]);
            }

            $this->billing->createAddonChargeInvoice($recurring, $setup, $notes);
            $this->billing->syncOrderSummary($order);

            // The new rows are revenue on the order: keep the order total equal
            // to the sum of its lines (the same recompute the order-creation
            // entry points do) so the order page and any later order-level
            // invoice agree with the lines instead of double-billing the add-on.
            $order->update(['total' => round((float) $order->items()->sum('total'), 2)]);

            app(AuditRecorder::class)->activity(AuditEvent::AddonAttached, $order->customer, [
                'order_id' => $order->id,
                'order_item_id' => $recurring->id,
                'product_addon_id' => $addon->id,
            ], $notes, null, $actor?->id);

            return $recurring;
        });
    }

    /**
     * Cancel an attached add-on at period end (WHMCS semantics): the schedule
     * stops — `next_billing_date = null` — and the current period, already
     * billed on attach, is neither refunded nor voided. Audit-logged and
     * followed by an order-summary recompute.
     */
    public function cancel(OrderItem $addonItem, ?string $reason = null, ?User $actor = null): void
    {
        if (! $addonItem->isAddon()) {
            throw new InvalidArgumentException('Only add-on lines can be cancelled.');
        }

        DB::transaction(function () use ($addonItem, $reason, $actor): void {
            $addonItem->update(['next_billing_date' => null]);

            // The relation can be cached on a model whose order row has since
            // changed (attach() loads the order via the item before syncing),
            // and syncOrderSummary() compares against the model's in-memory
            // original — a stale snapshot makes its update a silent no-op.
            $addonItem->load('order');
            $order = $addonItem->order;
            $description = "Add-on {$addonItem->product_name} cancelled on order {$order->order_number}";

            if ($reason !== null && $reason !== '') {
                $description .= " — Reason: {$reason}";
            }

            app(AuditRecorder::class)->activity(AuditEvent::AddonCancelled, $order->customer, [
                'order_id' => $order->id,
                'order_item_id' => $addonItem->id,
                'product_addon_id' => $addonItem->product_addon_id,
                'reason' => $reason,
            ], $description, null, $actor?->id);

            $this->billing->syncOrderSummary($order);
        });
    }
}
