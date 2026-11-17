<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UpgradeRequest;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data-quality check for the billing period an invoice line carries.
 *
 * A recurring service's schedule is driven by two columns on every invoice
 * line: `billed_due_date` (the period the line bills) and
 * `billed_cycle_months` (that period's length), plus `order_item_id` (the
 * order line it bills). The duplicate-billing net matches open invoices by
 * `billed_due_date`, and `advanceBillingSchedule()` skips a line without a
 * period — so a line that carries none (or only half of one) is invisible to
 * the schedule machinery: it neither blocks a renewal nor advances the
 * schedule when the money arrives.
 *
 * The rule is evaluated PER LINE:
 *   - the invoice must be OPEN (sent / overdue / partial);
 *   - the line must not be TRACKED (both period columns set);
 *   - the order item the line bills must be LIVE-RECURRING (a generation
 *     pointer and a cycle longer than zero).
 *
 * A line is attributed to its order item by `order_item_id` when it has one,
 * else by the order's single live-recurring item. A line that cannot be
 * attributed is still reported — it claims no period on an order that has
 * live recurring services — but it is NEVER repaired automatically.
 *
 * The service returns plain arrays: no console output, no notifications.
 */
class InvoicePeriodAudit
{
    /**
     * The activity-stream key of a repair (appended to activity_log).
     */
    public const ACTIVITY_ACTION = 'invoice.period_attached';

    /**
     * Invoice statuses that are an open demand on the customer.
     *
     * @var array<int, string>
     */
    public const OPEN_STATUSES = [
        Invoice::STATUS_SENT,
        Invoice::STATUS_OVERDUE,
        Invoice::STATUS_PARTIAL,
    ];

    public function __construct(private readonly BillingService $billing) {}

    /**
     * Every untracked line of every open invoice, plus the draft invoices
     * that carry the same shape (a draft was never sent, so it is not an open
     * demand — but it is worth a look).
     *
     * @return array{
     *     findings: array<int, array<string, mixed>>,
     *     drafts: array<int, array<string, mixed>>
     * }
     */
    public function scan(): array
    {
        $findings = [];
        $drafts = [];

        $invoices = Invoice::query()
            ->whereIn('status', [...self::OPEN_STATUSES, Invoice::STATUS_DRAFT])
            ->whereNotNull('order_id')
            // Only invoices that actually carry an untracked line.
            ->whereHas('items', fn ($query) => $query->where(fn ($inner) => $inner
                ->whereNull('billed_due_date')
                ->orWhereNull('billed_cycle_months')))
            ->with(['items', 'customer', 'order.items'])
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            $order = $invoice->order;

            // A dead order (cancelled, terminated, not active) owns dead
            // documents: nothing will ever bill them again.
            if ($order === null || $order->status !== Order::STATUS_ACTIVE) {
                continue;
            }

            // An upgrade invoice is a prorated one-off charge: it never
            // advances a schedule.
            if (UpgradeRequest::query()->where('invoice_id', $invoice->id)->exists()) {
                continue;
            }

            $liveItems = $order->items->filter(fn (OrderItem $item): bool => $this->isLiveRecurring($item, $order));

            // No live recurring service on the order: a line without a period
            // is legitimate here (a setup fee, an ad-hoc charge).
            if ($liveItems->isEmpty()) {
                continue;
            }

            $isDraft = $invoice->status === Invoice::STATUS_DRAFT;
            $draftLines = 0;

            foreach ($invoice->items as $line) {
                if ($this->isTracked($line)) {
                    continue;
                }

                $finding = $this->buildFinding($invoice, $order, $line, $liveItems);

                if ($finding === null) {
                    continue;
                }

                if ($isDraft) {
                    $draftLines++;

                    continue;
                }

                $findings[] = $finding;
            }

            if ($isDraft && $draftLines > 0) {
                $drafts[] = [
                    'invoice_id' => (int) $invoice->id,
                    'invoice_no' => (string) $invoice->invoice_no,
                    'order_no' => (string) ($order->order_number ?? '#'.$order->id),
                    'customer_name' => (string) ($invoice->customer?->full_name ?? 'Customer'),
                    'lines' => $draftLines,
                ];
            }
        }

        return ['findings' => $findings, 'drafts' => $drafts];
    }

    /**
     * Attach the missing period to one finding and move the generation
     * pointer, exactly as the renewal engine moves it.
     *
     * The line's period becomes the item's current `next_invoice_date` and
     * the item's `next_invoice_date` moves one cycle further: the period is
     * now invoiced. `next_billing_date` is the pay-by date and waits for the
     * money, so it is left untouched.
     *
     * Everything is re-validated here: the finding may have been resolved by
     * a human (or voided) since the scan ran.
     *
     * @return array<string, mixed>|null The repair, or null when the finding
     *                                   is no longer repairable.
     */
    public function repair(int $invoiceId, int $invoiceItemId, int $orderItemId): ?array
    {
        $invoice = Invoice::query()
            ->with(['items', 'customer', 'order.items'])
            ->find($invoiceId);

        if ($invoice === null || ! in_array($invoice->status, self::OPEN_STATUSES, true)) {
            return null;
        }

        $order = $invoice->order;

        if ($order === null || $order->status !== Order::STATUS_ACTIVE) {
            return null;
        }

        if (UpgradeRequest::query()->where('invoice_id', $invoice->id)->exists()) {
            return null;
        }

        $line = $invoice->items->firstWhere('id', $invoiceItemId);

        if ($line === null || $this->isTracked($line)) {
            return null;
        }

        $item = $order->items->firstWhere('id', $orderItemId);

        if ($item === null || ! $this->isLiveRecurring($item, $order)) {
            return null;
        }

        $cycleMonths = $this->cycleMonths($item, $order);
        $period = $item->next_invoice_date->toDateString();
        $advancedTo = $item->next_invoice_date->copy()->addMonths($cycleMonths)->toDateString();

        DB::transaction(function () use ($line, $item, $order, $period, $cycleMonths, $advancedTo): void {
            $line->update([
                // The exact link, not the product/date fallback: several lines
                // on one order can share a product, and a fallback that fails
                // would leave the invoice invisible to the paid advance again.
                'order_item_id' => (int) $item->id,
                'billed_due_date' => $period,
                'billed_cycle_months' => $cycleMonths,
            ]);

            // The generation pointer moves: the period is invoiced now. The
            // pay-by date waits for the money.
            $item->update(['next_invoice_date' => $advancedTo]);

            $this->billing->syncOrderSummary($order);
        });

        $this->recordRepair($invoice, $line, $item, $period, $cycleMonths, $advancedTo);

        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'invoice_item_id' => (int) $line->id,
            'order_item_id' => (int) $item->id,
            'billed_due_date' => $period,
            'billed_cycle_months' => $cycleMonths,
            'next_invoice_date_before' => $period,
            'next_invoice_date_after' => $advancedTo,
        ];
    }

    /**
     * Build the report entry of one untracked line, or null when the line is
     * exempt (it bills an order item that is not live-recurring).
     *
     * @param  Collection<int, OrderItem>  $liveItems
     * @return array<string, mixed>|null
     */
    private function buildFinding(Invoice $invoice, Order $order, InvoiceItem $line, $liveItems): ?array
    {
        $item = null;
        $reason = null;

        if ($line->order_item_id !== null) {
            $item = $order->items->firstWhere('id', (int) $line->order_item_id);

            if ($item === null) {
                // The link points at an order item this order does not carry.
                $reason = 'the line points at order item #'.$line->order_item_id.', which is not on this order';
            } elseif (! $this->isLiveRecurring($item, $order)) {
                // A setup-fee row (one_time) or a service whose schedule
                // ended: the line legitimately carries no period.
                return null;
            }
        } elseif ($liveItems->count() === 1) {
            $item = $liveItems->first();
        } else {
            $reason = 'the order has '.$liveItems->count().' live-recurring items and the line carries no order_item_id';
        }

        return $this->findingRow($invoice, $order, $line, $item, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function findingRow(Invoice $invoice, Order $order, InvoiceItem $line, ?OrderItem $item, ?string $reason): array
    {
        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'customer_id' => $invoice->customer_id,
            'customer_name' => (string) ($invoice->customer?->full_name ?? 'Customer'),
            'order_id' => (int) $order->id,
            'order_no' => (string) ($order->order_number ?? '#'.$order->id),
            'invoice_total' => (string) $invoice->total,
            'status' => (string) $invoice->status,
            'due_date' => $invoice->due_date?->toDateString(),
            'invoice_item_id' => (int) $line->id,
            'description' => (string) $line->description,
            'order_item_id' => $item?->id === null ? null : (int) $item->id,
            'order_item_name' => $item === null ? null : (string) $item->product_name,
            'period_due_date' => $item?->next_invoice_date?->toDateString(),
            'cycle_months' => $item === null ? null : $this->cycleMonths($item, $order),
            'repairable' => $item !== null,
            'reason' => $reason,
        ];
    }

    /**
     * Write the repair to the live audit trail (activity_log).
     */
    private function recordRepair(
        Invoice $invoice,
        InvoiceItem $line,
        OrderItem $item,
        string $period,
        int $cycleMonths,
        string $advancedTo,
    ): void {
        app(AuditRecorder::class)->activity(
            self::ACTIVITY_ACTION,
            $invoice->customer,
            [
                'invoice_id' => (int) $invoice->id,
                'invoice_no' => (string) $invoice->invoice_no,
                'order_id' => (int) $invoice->order_id,
                'invoice_item_id' => (int) $line->id,
                'order_item_id' => (int) $item->id,
                'order_item_name' => (string) $item->product_name,
                'billed_due_date' => $period,
                'billed_cycle_months' => $cycleMonths,
                'next_invoice_date_before' => $period,
                'next_invoice_date_after' => $advancedTo,
            ],
            "Invoice {$invoice->invoice_no}: billing period {$period} ({$cycleMonths} months) attached to order item #{$item->id} ({$item->product_name}).",
            $invoice,
        );
    }

    /**
     * A line is TRACKED when it carries both halves of its period.
     */
    private function isTracked(InvoiceItem $line): bool
    {
        return $line->billed_due_date !== null && $line->billed_cycle_months !== null;
    }

    /**
     * An order item is LIVE-RECURRING when its schedule is still armed and
     * its cycle is longer than zero (`one_time` never renews).
     */
    private function isLiveRecurring(OrderItem $item, Order $order): bool
    {
        return $item->next_invoice_date !== null && $this->cycleMonths($item, $order) > 0;
    }

    /**
     * The cycle length of an order item, in months.
     *
     * `order_items.billing_cycle` is nullable by contract ("the order's
     * billing_cycle supplies it"), so the same fallback the renewal engine
     * uses applies here.
     */
    private function cycleMonths(OrderItem $item, Order $order): int
    {
        return Order::CYCLE_MONTHS[$item->billing_cycle ?? $order->billing_cycle] ?? 1;
    }
}
