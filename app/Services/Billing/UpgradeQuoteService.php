<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\OptionPricingResolver;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * UpgradeQuoteService — WHMCS-style pricing for a mid-cycle product change.
 *
 * Quotes the payable (or credit) for switching an order's served product to a
 * target product. Both sides are prorated by days-remaining/total-days, so the
 * result is debited − credited (plus any setup-fee difference), matching
 * WHMCS's "payable = debited − credited" with both sides prorated.
 *
 * Pure read service: no DB writes.
 */
final class UpgradeQuoteService
{
    /**
     * Price a mid-cycle change from the order's served product to $to.
     *
     * @param  Order  $order  The order being changed (its served item is the
     *                        "from" side; its billing cycle sets the period).
     * @param  Product  $to  Target product (priced for $toCycle, or the
     *                       order's cycle when $toCycle is null, i.e. WHMCS's
     *                       newproductbillingcycle).
     * @param  CarbonImmutable|null  $changeDate  When the change happens
     *                                            (defaults to today).
     * @param  string|null  $toCycle  Target billing cycle; null keeps the
     *                                order's cycle.
     * @param  array<int|string, mixed>|null  $toSelections  Target option
     *                                                       selections keyed by link id; null
     *                                                       prices the target without options.
     * @return array{prorated:bool,period_days:int,to_period_days:int,proration_days:int,credited:float,debited:float,setup_fee_difference:float,total:float,payable:float,credit:float,cycle_changed:bool,change_type:string,option_adjustment:float,quantity:int,from:array{product_id:int,product_name:string,unit_price:float,billing_cycle:string},to:array{product_id:int,product_name:string,price:float,billing_cycle:string,setup_fee:float}}
     *
     * Money fields (credited/debited/total/payable/credit) are AGGREGATE — the
     * per-unit rates scaled by the served item's quantity (WHMCS prorates the
     * recurring amount, which is unit x quantity). 'quantity' states the scale
     * factor; from.unit_price / to.price / option_adjustment stay per-unit.
     *
     * @throws DomainException when $toCycle is not a recurring cycle, the
     *                         target product has no pricing for the target
     *                         cycle, or the order has no served item.
     */
    public function quote(Order $order, Product $to, ?CarbonImmutable $changeDate = null, ?string $toCycle = null, ?array $toSelections = null): array
    {
        $cycle = (string) $order->billing_cycle;
        $months = Order::CYCLE_MONTHS[$cycle] ?? 1;

        if ($toCycle !== null && (Order::CYCLE_MONTHS[$toCycle] ?? 0) <= 0) {
            throw new DomainException("Invalid target billing cycle {$toCycle}.");
        }

        $toMonths = Order::CYCLE_MONTHS[$toCycle ?? $cycle] ?? 1;
        $cycleChanged = $toCycle !== null && $toCycle !== $cycle;

        // From side: the order's served item is authoritative, even when its
        // product differs from the order summary's product.
        $served = $this->servedItem($order);
        $fromUnitPrice = (float) $served->unit_price;
        $fromProduct = $served->product ?? $order->product;

        $toCycleKey = $toCycle ?? $cycle;
        $pricing = $to->pricing()->where('billing_cycle', $toCycleKey)->first();
        if ($pricing === null) {
            throw new DomainException("Target product has no pricing for the {$toCycleKey} cycle.");
        }

        $toPrice = (float) $pricing->price;
        $toSetupFee = (float) ($pricing->setup_fee ?? 0);
        $fromSetupFee = (float) ($fromProduct?->pricing()->where('billing_cycle', $cycle)->first()?->setup_fee ?? 0);

        // Config-options upgrades price the target side as base + resolver
        // adjustment for the target cycle; without selections the unit price
        // is the plain cycle price (pre-extension behaviour).
        $optionAdjustment = 0.0;
        $toUnitPrice = $toPrice;

        if ($toSelections !== null) {
            $optionAdjustment = round(
                (new OptionPricingResolver)->adjustment($to, $toSelections, $toCycle === null ? $cycle : $toCycle),
                2
            );
            $toUnitPrice = $toPrice + $optionAdjustment;
        }

        $change = $changeDate ?? CarbonImmutable::today('Asia/Kolkata');

        // Period bounds: cycle start is the previous due date, unless the
        // order records a different last billing date. When the target cycle
        // changes, the debit side is measured over a full NEW cycle (the same
        // subMonths-from-end window the old period uses) so remaining days of
        // the old cycle spend against the new cycle's length.
        $end = $order->next_billing_date === null ? null : CarbonImmutable::parse($order->next_billing_date);
        $periodDays = 0;
        $prorationDays = 0;
        $toPeriodDays = 0;

        if ($end !== null) {
            $start = $end->subMonths($months);
            if ($order->last_billing_date !== null && ! $order->last_billing_date->eq($start)) {
                $start = CarbonImmutable::parse($order->last_billing_date);
            }

            $periodDays = (int) $start->diff($end)->days;
            $prorationDays = $change->lte($end) ? (int) $change->diff($end)->days : 0;
            $toPeriodDays = $cycleChanged
                ? (int) $end->subMonths($toMonths)->diff($end)->days
                : $periodDays;
        }

        $prorated = (bool) AppSettings::get('product_prorated_charges', '1') && $periodDays > 0;

        if ($prorated) {
            $credited = round($fromUnitPrice * $prorationDays / $periodDays, 2);
            $debited = round($toUnitPrice * $prorationDays / $toPeriodDays, 2);
        } else {
            // Setting off, free service, or a degenerate period: full amounts.
            $credited = $fromUnitPrice;
            $debited = $toUnitPrice;
            $prorationDays = 0;
        }

        // WHMCS prorates the RECURRING amount, which is unit × quantity: scale
        // both sides by the served quantity so a qty-2 service pays the delta
        // for both units — its renewals bill quantity × unit price, and an
        // unscaled upgrade would undercharge by (qty − 1) × delta. The setup
        // fee difference stays flat: a setup fee is charged once per order.
        $quantity = max(1, (int) $served->quantity);
        if ($quantity > 1) {
            $credited = round($credited * $quantity, 2);
            $debited = round($debited * $quantity, 2);
        }

        $setupFeeDifference = round(max(0.0, $toSetupFee - $fromSetupFee), 2);
        $total = round($debited - $credited + $setupFeeDifference, 2);
        $payable = max(0.0, $total);
        $credit = max(0.0, round(-$total, 2));

        $monthlyFrom = $fromUnitPrice / $months;
        $monthlyTo = $toUnitPrice / $toMonths;
        if (abs($monthlyTo - $monthlyFrom) < 0.005) {
            $changeType = 'equal';
        } elseif ($monthlyTo > $monthlyFrom) {
            $changeType = 'upgrade';
        } else {
            $changeType = 'downgrade';
        }

        return [
            'prorated' => $prorated,
            'period_days' => $periodDays,
            'to_period_days' => $toPeriodDays,
            'proration_days' => $prorationDays,
            'credited' => $credited,
            'debited' => $debited,
            'setup_fee_difference' => $setupFeeDifference,
            'total' => $total,
            'payable' => $payable,
            'credit' => $credit,
            'cycle_changed' => $cycleChanged,
            'change_type' => $changeType,
            'option_adjustment' => $optionAdjustment,
            'quantity' => $quantity,
            'from' => [
                'product_id' => (int) $served->product_id,
                'product_name' => (string) ($served->product_name ?? $served->product?->name ?? ''),
                'unit_price' => $fromUnitPrice,
                'billing_cycle' => $cycle,
            ],
            'to' => [
                'product_id' => (int) $to->id,
                'product_name' => (string) $to->name,
                'price' => $toPrice,
                'billing_cycle' => $toCycle ?? $cycle,
                'setup_fee' => $toSetupFee,
            ],
        ];
    }

    /**
     * The order's served item: the first matching the order's product, else
     * the first non-addon item (addons are never the served product).
     */
    private function servedItem(Order $order): OrderItem
    {
        $served = $order->items->first(
            fn (OrderItem $item) => $item->product_addon_id === null && $item->product_id === $order->product_id,
        );

        $served ??= $order->items->first(fn (OrderItem $item) => $item->product_addon_id === null);

        if ($served === null) {
            throw new DomainException('Order has no served product item.');
        }

        return $served;
    }
}
