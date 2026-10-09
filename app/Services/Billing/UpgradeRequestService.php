<?php

namespace App\Services\Billing;

use App\Models\CustomerWallet;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPricing;
use App\Models\ProductUpgradePath;
use App\Models\UpgradeRequest;
use App\Services\OptionPricingResolver;
use App\Services\OrderConfigSnapshot;
use App\Services\OrderNumberService;
use App\Services\UpgradeEmailService;
use App\Support\AppSettings;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * UpgradeRequestService — the WHMCS product-upgrade lifecycle.
 *
 * place() → approve() → apply() materializes a pending request: approval
 * raises an invoice (payable) or grants the wallet credit and applies
 * immediately (downgrade/zero-net), and apply() switches the order's served
 * item to the target product at the target price. cancel() aborts a pending
 * request and voids its unpaid invoice; cancelUnpaidForOrder() is the
 * renewal-cron hook that cancels upgrade invoices still unpaid when the
 * renewal invoice is generated (WHMCS: one open upgrade per service).
 */
class UpgradeRequestService
{
    public function __construct(
        private readonly UpgradeQuoteService $quotes,
        private readonly BillingService $billing,
        private readonly OrderNumberService $numbers,
        private readonly UpgradeEmailService $emails,
    ) {}

    /**
     * Register a new upgrade request: guards, then a quote snapshot persisted
     * with a generated UPG-{YEAR}-{seq} number. Fires the upgrade_requested
     * email (silently skipped when the template is missing or the customer has
     * no linked user email).
     *
     * The quote is computed up front because the downgrade gate needs its
     * change_type; its missing-pricing DomainException propagates as-is.
     *
     * @throws DomainException on any failed guard.
     */
    public function place(Order $order, Product $to, ?string $notes = null, ?string $toBillingCycle = null, ?array $toSelections = null): UpgradeRequest
    {
        $cycle = (string) $order->billing_cycle;
        $cycleMonths = Order::CYCLE_MONTHS[$cycle] ?? 0;

        if ($cycleMonths <= 0) {
            throw new DomainException('Upgrades require a recurring billing cycle.');
        }

        $quote = $this->quotes->quote($order, $to, null, $toBillingCycle, $toSelections);

        if (! AppSettings::get('product_enable_upgrades', '1')) {
            throw new DomainException('Upgrades are disabled.');
        }

        if ($quote['change_type'] === 'downgrade' && ! AppSettings::get('product_enable_downgrades', '0')) {
            throw new DomainException('Downgrades are disabled.');
        }

        if ($order->status !== Order::STATUS_ACTIVE) {
            throw new DomainException('Only active services can be upgraded.');
        }

        if ($to->status !== 'active') {
            throw new DomainException('The target product is not available.');
        }

        $path = ProductUpgradePath::query()
            ->where('from_product_id', $order->product_id)
            ->where('to_product_id', $to->id)
            ->where('enabled', true)
            ->first();

        if ($path === null) {
            throw new DomainException(
                "No enabled upgrade path from {$quote['from']['product_name']} to {$quote['to']['product_name']}.",
            );
        }

        // The predefined lists are the authority: a downgrade pair requires the
        // target on the product's downgrade list, any other pair the upgrade
        // list. The listing filters by the same rule; this is the enforcement
        // point for hand-crafted requests that never pass through the listing.
        $direction = $path->direction ?? 'both';
        $isDowngrade = $quote['change_type'] === 'downgrade';
        $allowed = $isDowngrade
            ? in_array($direction, ['downgrade', 'both'], true)
            : in_array($direction, ['upgrade', 'both'], true);

        if (! $allowed) {
            throw new DomainException(sprintf(
                'This product is not on the %s list for %s.',
                $isDowngrade ? 'downgrade' : 'upgrade',
                $quote['from']['product_name'],
            ));
        }

        $request = DB::transaction(function () use ($order, $to, $quote, $notes, $toBillingCycle, $toSelections): UpgradeRequest {
            $hasOpen = UpgradeRequest::query()
                ->where('order_id', $order->id)
                ->where('status', UpgradeRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->exists();

            if ($hasOpen) {
                throw new DomainException('Previous upgrade for this service is still pending or unpaid.');
            }

            // Option-only change on the same product: configoptions. Any
            // product switch is a product upgrade even when options ride along.
            $upgradeType = $toSelections !== null && (int) $to->id === (int) $order->product_id
                ? 'configoptions'
                : 'product';

            if (! in_array($quote['change_type'], ['upgrade', 'downgrade', 'equal'], true)) {
                throw new DomainException('Invalid change type.');
            }

            return UpgradeRequest::create([
                'upgrade_no' => $this->numbers->next('UPG'),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'from_product_id' => $order->product_id,
                'to_product_id' => $to->id,
                'upgrade_type' => $upgradeType,
                'change_type' => $quote['change_type'],
                'status' => UpgradeRequest::STATUS_PENDING,
                'billing_cycle' => $order->billing_cycle,
                'to_billing_cycle' => $toBillingCycle,
                'options' => $toSelections,
                'credited' => $quote['credited'],
                'debited' => $quote['debited'],
                'setup_fee' => $quote['setup_fee_difference'],
                'payable' => $quote['payable'],
                'credit_amount' => $quote['credit'],
                'proration_days' => $quote['proration_days'],
                'period_days' => $quote['period_days'],
                'notes' => $notes,
            ]);
        });

        $this->emails->send($request, 'upgrade_requested');

        return $request;
    }

    /**
     * Approve a pending request (admin manual approval or the controller
     * auto-approval path): payable → raise the upgrade invoice (status stays
     * pending, awaiting payment); credit → grant the wallet credit and apply
     * immediately in the same transaction (approval IS the credit grant);
     * zero-net → apply immediately with no invoice and no credit.
     *
     * @throws DomainException when the request is not pending.
     */
    public function approve(UpgradeRequest $request): UpgradeRequest
    {
        if ($request->status !== UpgradeRequest::STATUS_PENDING) {
            throw new DomainException('Upgrade is not pending.');
        }

        return DB::transaction(function () use ($request): UpgradeRequest {
            $request = UpgradeRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->first();

            if ($request === null || $request->status !== UpgradeRequest::STATUS_PENDING || $request->invoice_id !== null) {
                throw new DomainException('Upgrade is not pending.');
            }

            $payable = (float) $request->payable;
            $credit = (float) $request->credit_amount;

            if ($payable > 0) {
                $fromName = (string) ($request->fromProduct?->name ?? '');
                $toName = (string) ($request->toProduct?->name ?? '');
                $cycleSuffix = $request->to_billing_cycle !== null && $request->to_billing_cycle !== $request->billing_cycle
                    ? ' ('.ucfirst(str_replace('_', ' ', (string) $request->to_billing_cycle)).')'
                    : '';

                $configSuffix = $request->upgrade_type === 'configoptions' ? ' (configuration change)' : '';

                $description = ($request->proration_days > 0
                    ? "Upgrade from {$fromName} to {$toName}{$cycleSuffix} — prorated ({$request->proration_days} days)"
                    : "Upgrade from {$fromName} to {$toName}{$cycleSuffix}").$configSuffix;

                $invoice = $this->billing->createWithItems(
                    [
                        'customer_id' => $request->customer_id,
                        'order_id' => $request->order_id,
                        'amount' => $payable,
                        'status' => Invoice::STATUS_SENT,
                        'due_date' => CarbonImmutable::today('Asia/Kolkata')->addDays(7),
                        'notes' => "Upgrade {$request->upgrade_no}: {$fromName} → {$toName}",
                    ],
                    [
                        [
                            'description' => $description,
                            'quantity' => 1,
                            'unit_price' => $payable,
                            'total' => $payable,
                            'product_id' => $request->to_product_id,
                        ],
                    ],
                    $this->billing->resolvePlaceOfSupply((int) $request->customer_id),
                );

                $request->update([
                    'invoice_id' => $invoice->id,
                    'approved_at' => now(),
                ]);

                // No lifecycle email here — the invoice_created template
                // already covers the customer on approval.
                return $request;
            }

            if ($credit > 0) {
                CustomerWallet::create([
                    'customer_id' => $request->customer_id,
                    'type' => 'credit',
                    'balance_type' => 'credit',
                    'amount' => $credit,
                    'description' => "Downgrade credit — {$request->upgrade_no} ({$request->fromProduct?->name} → {$request->toProduct?->name})",
                ]);
            }

            return $this->apply($request, true);
        });
    }

    /**
     * Materialize the request: switch the order's served item to the target
     * product at its price for the request's cycle. The item's config_options,
     * billing_cycle and next_billing_date are preserved; a free service (null
     * next_billing_date) gains its first due date = today + cycle. Idempotent:
     * a non-pending request is returned unchanged.
     *
     * @throws DomainException when the served item or the target pricing is missing.
     */
    public function apply(UpgradeRequest $request, bool $insideTransaction = false): UpgradeRequest
    {
        if ($request->status !== UpgradeRequest::STATUS_PENDING) {
            return $request;
        }

        $order = $request->order;

        $item = $order->items->first(
            fn (OrderItem $item) => $item->product_addon_id === null && (int) $item->product_id === (int) $request->from_product_id,
        ) ?? $order->items->first(fn (OrderItem $item) => $item->product_addon_id === null);

        if ($item === null) {
            throw new DomainException('Served order item not found.');
        }

        $cycle = (string) ($request->to_billing_cycle ?? $request->billing_cycle);
        $pricing = ProductPricing::query()
            ->where('product_id', $request->to_product_id)
            ->where('billing_cycle', $cycle)
            ->first();

        if ($pricing === null) {
            throw new DomainException("Target product has no pricing for the {$cycle} cycle.");
        }

        $work = function () use ($request, $order, $item, $pricing, $cycle): UpgradeRequest {
            $itemData = [
                'product_id' => $request->to_product_id,
                'product_name' => (string) ($request->toProduct?->name ?? ''),
                'unit_price' => (float) $pricing->price,
                'total' => round((float) $pricing->price * (int) $item->quantity, 2),
            ];

            // Config-options upgrades rewrite the served price to base plus
            // the option adjustment and replace the old snapshot with the new
            // FULL configuration shape. When the target product has no option
            // links (options === null), clear any stale config_options from the
            // previous product — otherwise invoices and provisioning would
            // carry the old product's configuration onto the new product.
            if ($request->options !== null) {
                $to = $request->toProduct;
                $adjustment = (new OptionPricingResolver)->adjustment($to, $request->options, $cycle);
                $unitPrice = (float) $pricing->price + $adjustment;

                $itemData['unit_price'] = $unitPrice;
                $itemData['total'] = round($unitPrice * (int) $item->quantity, 2);
                $itemData['config_options'] = app(OrderConfigSnapshot::class)->capture($to, null, $request->options, $cycle);
            } else {
                $itemData['config_options'] = null;
            }

            $orderData = [
                'product_id' => $request->to_product_id,
            ];

            // A cycle change moves both the served item and the order summary
            // onto the target cycle so renewals bill the new cadence.
            if ($request->to_billing_cycle !== null) {
                $itemData['billing_cycle'] = $request->to_billing_cycle;
                $orderData['billing_cycle'] = $request->to_billing_cycle;

                // Re-anchor the item's billing history to the start of the
                // first full NEW cycle. Without this, the once-per-cycle
                // renewal guard (last_billing_date + cycleMonths > today)
                // compares the OLD cycle's last bill against the new, longer
                // cadence and skips the first new-cycle renewal entirely —
                // e.g. a monthly→annual change with next_due 2026-05-01 and
                // last_billing_date 2026-04-06 would not bill the year until
                // 2027-04-06. The anchor makes the guard evaluate to the due
                // date itself, so the preserved next_billing_date bills on
                // schedule. Only when a schedule already exists: a free→paid
                // upgrade leaves last_billing_date null (never billed).
                if ($item->next_billing_date !== null) {
                    $itemData['last_billing_date'] = CarbonImmutable::parse($item->next_billing_date)
                        ->subMonths(Order::CYCLE_MONTHS[$request->to_billing_cycle] ?? 1)
                        ->toDateString();
                }
            }

            if ($order->next_billing_date === null) {
                $dueDate = CarbonImmutable::today('Asia/Kolkata')
                    ->addMonths(Order::CYCLE_MONTHS[$cycle] ?? 1)
                    ->toDateString();

                $itemData['next_billing_date'] = $dueDate;
                $orderData['next_billing_date'] = $dueDate;
            }

            $item->update($itemData);
            $order->update($orderData);

            $request->update([
                'status' => UpgradeRequest::STATUS_APPLIED,
                'applied_at' => now(),
            ]);

            app(AuditRecorder::class)->activity(AuditEvent::UpgradeApplied, $order->customer, [
                'order_id' => $order->id,
                'upgrade_request_id' => $request->id,
                'order_item_id' => $item->id,
                'to_product_id' => $request->to_product_id,
            ], "Upgrade {$request->upgrade_no} applied on order {$order->order_number}: {$request->fromProduct?->name} → {$request->toProduct?->name}");

            return $request;
        };

        if ($insideTransaction) {
            $work();
        } else {
            DB::transaction($work);
        }

        $this->emails->send($request, 'upgrade_applied');

        return $request;
    }

    /**
     * Cancel a pending request: void its invoice when it is unpaid and has no
     * payments (a paid or partially-paid upgrade invoice blocks cancellation),
     * then mark the request cancelled with the reason appended to the notes.
     *
     * @throws DomainException when the request is not pending, or its invoice has payments.
     */
    public function cancel(UpgradeRequest $request, ?string $reason = null): UpgradeRequest
    {
        if ($request->status !== UpgradeRequest::STATUS_PENDING) {
            throw new DomainException('Upgrade is not pending.');
        }

        $request = DB::transaction(function () use ($request, $reason): UpgradeRequest {
            $request = UpgradeRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->first();

            if ($request === null || $request->status !== UpgradeRequest::STATUS_PENDING) {
                throw new DomainException('Upgrade is not pending.');
            }

            $invoice = $request->invoice_id !== null && $request->invoice !== null ? $request->invoice : null;

            if ($invoice !== null) {
                $fullyPaid = $invoice->isFullyPaid();
                $hasPayments = $invoice->payments()->exists();

                if (! $fullyPaid && ! $hasPayments) {
                    $invoice->update(['status' => Invoice::STATUS_VOID]);
                } else {
                    throw new DomainException('Cannot cancel: the upgrade invoice has payments.');
                }
            }

            $notes = (string) $request->notes;

            if ($reason !== null && $reason !== '') {
                $notes = $notes === '' ? $reason : $notes."\n".$reason;
            }

            $request->update([
                'status' => UpgradeRequest::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'notes' => $notes === '' ? null : $notes,
            ]);

            return $request;
        });

        $this->emails->send($request, 'upgrade_cancelled');

        return $request;
    }

    /**
     * Renewal-cron hook: cancel every pending request on the order whose
     * invoice is not fully paid (the renewal invoice supersedes an unpaid
     * upgrade). Requests awaiting approval (no invoice) and applied requests
     * are left alone. Never throws — a missing invoice row or a
     * payment-carrying invoice is skipped, not fatal.
     */
    public function cancelUnpaidForOrder(Order $order, ?DateTimeInterface $asOf = null): int
    {
        $count = 0;

        $requests = $order->upgradeRequests()
            ->where('status', UpgradeRequest::STATUS_PENDING)
            ->whereNotNull('invoice_id')
            ->get();

        foreach ($requests as $request) {
            try {
                $invoice = $request->invoice;

                if ($invoice === null || $invoice->isFullyPaid()) {
                    continue;
                }

                $this->cancel($request, 'Cancelled: renewal invoice generated while the upgrade was unpaid.');
                $count++;
            } catch (DomainException) {
                // A partially-paid invoice mid-reconciliation belongs to the
                // payment flow, not the renewal cron.
            }
        }

        return $count;
    }

    /**
     * Auto-approval rule for the place-time controllers: payable requests
     * auto-approve unless the product_approval_required setting is on.
     * Credit (downgrade) requests never auto-approve — approval IS the grant.
     */
    public function shouldAutoApprove(UpgradeRequest $request): bool
    {
        return (float) $request->payable > 0 && ! AppSettings::get('product_approval_required', '0');
    }

    /**
     * Downgrade detection: the persisted change_type column is the marker.
     */
    public function isDowngrade(UpgradeRequest $request): bool
    {
        return $request->change_type === 'downgrade';
    }
}
