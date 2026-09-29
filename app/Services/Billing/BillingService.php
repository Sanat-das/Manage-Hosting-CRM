<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Events\InvoicePaid;
use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\GstSetting;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\OrderNumberService;
use App\Services\OrderService;
use App\Support\AppSettings;
use App\Support\GstStateCodes;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * BillingService — billing engine facade (Session 3A.1).
 *
 * Ports, in Eloquent:
 *  - Modules\Billing\InvoiceModel::generateNumber L18 + createWithItems L36 + markPaid L377
 *  - Modules\Billing\PaymentReconciliation::handlePartialPayment + handleOverpayment
 *  - Modules\Automation\Automation::processRecurringBilling L26 (with the
 *    renewal-IGST fix from decisions.md #6)
 *
 * Exposes clean, well-named public methods for the admin UI task to consume.
 */
class BillingService
{
    public function __construct(private readonly OrderService $orderService) {}

    /**
     * Generate the next invoice number: INV-{FY}-{seq} padded to 5, scoped to
     * the Indian financial year (Apr–Mar) and race-safe via the sequences
     * row-lock counter. Port of InvoiceModel::generateNumber L18-26.
     */
    public function generateNumber(): string
    {
        return app(OrderNumberService::class)->nextForFinancialYear('INV', now());
    }

    /**
     * Create an invoice with line items, applying the GST engine.
     *
     * Port of InvoiceModel::createWithItems L36-256. The invoice total is always
     * recomputed as amount + tax − discount (discount AFTER tax); any 'tax' or
     * 'total' passed in $invoiceData is overridden by the computed values.
     *
     * @param  array  $invoiceData  customer_id, amount, status, due_date, notes, (order_id, discount).
     * @param  array  $items  Each item: description, quantity, unit_price, total, (product_id).
     * @param  string|null  $customerStateCode  Customer's 2-letter state code.
     */
    public function createWithItems(array $invoiceData, array $items, ?string $customerStateCode = null): Invoice
    {
        $settings = GstTaxService::loadSettings(GstSetting::find(1));
        $computed = GstTaxService::computeInvoiceTaxes($items, $settings, $customerStateCode);

        $amount = (float) ($invoiceData['amount'] ?? 0);
        $discount = (float) ($invoiceData['discount'] ?? 0);
        $invoiceTax = $computed['invoice'];

        return DB::transaction(function () use ($invoiceData, $computed, $amount, $discount, $invoiceTax, $customerStateCode) {
            $attributes = array_merge($invoiceData, [
                'invoice_no' => $this->generateNumber(),
                'tax' => $invoiceTax['tax'],
                'gst_enabled' => $invoiceTax['gst_enabled'],
                'cgst_rate' => $invoiceTax['cgst_rate'],
                'cgst_amount' => $invoiceTax['cgst_amount'],
                'sgst_rate' => $invoiceTax['sgst_rate'],
                'sgst_amount' => $invoiceTax['sgst_amount'],
                'igst_rate' => $invoiceTax['igst_rate'],
                'igst_amount' => $invoiceTax['igst_amount'],
                'total' => round($amount + $invoiceTax['tax'] - $discount, 2),
            ]);

            // Snapshot the place of supply on the document; a null caller
            // value leaves the column null (nothing to record).
            if ($customerStateCode !== null) {
                $attributes['place_of_supply_code'] = $customerStateCode;
            }

            $invoice = Invoice::create($attributes);

            foreach ($computed['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'],
                    'total' => $item['total'],
                    // Configurable options the line bills for; absent on
                    // hand-written invoice lines.
                    'config_options' => $item['config_options'] ?? null,
                    'gst_enabled' => $item['gst_enabled'],
                    'gst_rate' => $item['gst_rate'],
                    'gst_type' => $item['gst_type'],
                    'cgst_rate' => $item['cgst_rate'],
                    'cgst_amount' => $item['cgst_amount'],
                    'sgst_rate' => $item['sgst_rate'],
                    'sgst_amount' => $item['sgst_amount'],
                    'igst_rate' => $item['igst_rate'],
                    'igst_amount' => $item['igst_amount'],
                ]);
            }

            return $invoice;
        });
    }

    /**
     * Update an existing invoice and its line items, re-running the GST engine.
     *
     * Mirrors createWithItems: the total is always recomputed as
     * amount + tax − discount and every computed tax field overrides whatever
     * arrived in $invoiceData. Amount-locked columns (invoice_no, paid_at,
     * paid_amount, created_at) are never written here.
     *
     * @param  array  $invoiceData  customer_id, amount, status, due_date, notes, (discount).
     * @param  array  $items  Each item: description, quantity, unit_price, total, (product_id).
     * @param  string|null  $customerStateCode  Customer's 2-letter state code.
     */
    public function updateWithItems(Invoice $invoice, array $invoiceData, array $items, ?string $customerStateCode = null): Invoice
    {
        // Restore per-line detail the form cannot post back BEFORE the tax
        // engine runs: GstTaxService::calculateItemTax() reads `product_id`,
        // so a line that lost its product would silently lose its per-product
        // GST treatment too.
        $items = $this->carryForwardLineDetails($invoice, $items);

        $settings = GstTaxService::loadSettings(GstSetting::find(1));
        $computed = GstTaxService::computeInvoiceTaxes($items, $settings, $customerStateCode);

        $amount = (float) ($invoiceData['amount'] ?? 0);
        $discount = (float) ($invoiceData['discount'] ?? 0);
        $invoiceTax = $computed['invoice'];

        DB::transaction(function () use ($invoice, $invoiceData, $computed, $amount, $discount, $invoiceTax, $customerStateCode) {
            $attributes = array_merge($invoiceData, [
                'tax' => $invoiceTax['tax'],
                'gst_enabled' => $invoiceTax['gst_enabled'],
                'cgst_rate' => $invoiceTax['cgst_rate'],
                'cgst_amount' => $invoiceTax['cgst_amount'],
                'sgst_rate' => $invoiceTax['sgst_rate'],
                'sgst_amount' => $invoiceTax['sgst_amount'],
                'igst_rate' => $invoiceTax['igst_rate'],
                'igst_amount' => $invoiceTax['igst_amount'],
                'total' => round($amount + $invoiceTax['tax'] - $discount, 2),
            ]);

            // Re-snapshot the place of supply when the caller resolved one;
            // null leaves the stored code untouched.
            if ($customerStateCode !== null) {
                $attributes['place_of_supply_code'] = $customerStateCode;
            }

            $invoice->update($attributes);

            $invoice->items()->delete();

            foreach ($computed['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'],
                    'total' => $item['total'],
                    'config_options' => $item['config_options'] ?? null,
                    'gst_enabled' => $item['gst_enabled'],
                    'gst_rate' => $item['gst_rate'],
                    'gst_type' => $item['gst_type'],
                    'cgst_rate' => $item['cgst_rate'],
                    'cgst_amount' => $item['cgst_amount'],
                    'sgst_rate' => $item['sgst_rate'],
                    'sgst_amount' => $item['sgst_amount'],
                    'igst_rate' => $item['igst_rate'],
                    'igst_amount' => $item['igst_amount'],
                ]);
            }
        });

        return $invoice->fresh();
    }

    /**
     * Restore the per-line detail an invoice form cannot post back.
     *
     * updateWithItems() deletes and recreates every line, so anything absent
     * from the payload is lost. The admin form submits description / quantity
     * / unit_price plus each existing line's `id`; a line's `product_id` and
     * its configurable-option snapshot live only in the database. Without this
     * restore, editing an invoice's notes would blank the product link — which
     * also drives per-product GST — and erase what each line bills for.
     *
     * Matched by id wherever the payload supplies one, which is exact: it
     * survives reordering, a retyped description, and two lines that read
     * identically ("Cloud VPS - Monthly" twice, for units configured 8 GB and
     * 16 GB).
     *
     * Callers that supply no ids fall back to matching on the description, and
     * only where it appears exactly once on each side among the lines no id
     * already claimed — anything ambiguous carries nothing rather than attach
     * one line's product and configuration to another. A line the admin just
     * added has no id and no matching description, so it correctly inherits
     * nothing.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function carryForwardLineDetails(Invoice $invoice, array $items): array
    {
        $previous = $invoice->items()->get();
        $previousById = $previous->keyBy('id');

        $claimedIds = [];
        foreach ($items as $item) {
            $id = $this->lineId($item);

            if ($id !== null && $previousById->has($id)) {
                $claimedIds[$id] = true;
            }
        }

        // Fallback index: only the stored lines no id claimed, and only the
        // submitted lines that named no id.
        $fallbackLines = [];
        $fallbackCounts = [];

        foreach ($previous as $line) {
            if (isset($claimedIds[(int) $line->id])) {
                continue;
            }

            $description = (string) $line->description;
            $fallbackCounts[$description] = ($fallbackCounts[$description] ?? 0) + 1;
            $fallbackLines[$description] ??= $line;
        }

        $incomingCounts = [];

        foreach ($items as $item) {
            if ($this->lineId($item) !== null) {
                continue;
            }

            $description = (string) ($item['description'] ?? '');
            $incomingCounts[$description] = ($incomingCounts[$description] ?? 0) + 1;
        }

        // A stored line can be claimed once. The HTTP path already rejects a
        // repeated id (`distinct`), but this is a public service method: a
        // second reference to the same line inherits nothing rather than
        // duplicating one line's product — and its per-product GST treatment —
        // onto a line that is not it.
        $consumed = [];
        $resolved = [];

        foreach ($items as $item) {
            $id = $this->lineId($item);
            $match = null;

            if ($id !== null) {
                $match = $previousById->get($id);
            } else {
                $description = (string) ($item['description'] ?? '');

                if (($fallbackCounts[$description] ?? 0) === 1 && ($incomingCounts[$description] ?? 0) === 1) {
                    $match = $fallbackLines[$description];
                }
            }

            if ($match !== null && ! isset($consumed[(int) $match->id])) {
                $consumed[(int) $match->id] = true;

                // Anything the caller stated explicitly wins; the stored line
                // only fills the gaps.
                $item = array_merge($item, [
                    'product_id' => $item['product_id'] ?? $match->product_id,
                    'config_options' => $item['config_options'] ?? $match->config_options,
                ]);
            }

            $resolved[] = $item;
        }

        return $resolved;
    }

    /**
     * The stored line id a submitted item refers to, or null for a new line.
     *
     * @param  array<string, mixed>  $item
     */
    private function lineId(array $item): ?int
    {
        $id = $item['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Create the draft invoice that accompanies an order at creation time.
     *
     * Single shared path for every order entry point (admin form, admin cart,
     * client storefront) so an order is immediately billable everywhere. The
     * GST engine runs with the customer's state code (intra-state fallback),
     * the due date follows the same 7-day convention as the recurring job.
     *
     * @param  string|null  $notes  Invoice note override; defaults to the
     *                              standard "Invoice for order …" convention.
     */
    public function createInvoiceForOrder(Order $order, ?string $notes = null): Invoice
    {
        $productName = $order->product?->name ?? 'Order';

        // One invoice line per order item: multi-product orders (admin form)
        // carry several items, each described with its own product and cycle
        // (order_items.billing_cycle falls back to the order's cycle for
        // legacy lines). Single-item orders render exactly as before.
        $items = $order->items
            ->map(fn (OrderItem $item) => $this->linePayload($item, (string) $order->billing_cycle))
            ->all();

        return $this->createWithItems([
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'amount' => (float) $order->total,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
            'notes' => $notes ?? "Invoice for order {$order->order_number} — {$productName} ({$order->billing_cycle})",
        ], $items, $this->resolveCustomerStateCode((int) $order->customer_id));
    }

    /**
     * Create the immediate charge invoice for an add-on attached to a live
     * order (WHMCS-style post-signup attach, no proration).
     *
     * One line per charge: the recurring add-on row, plus the setup-fee row
     * when one exists. Lines reuse the order-invoice description convention;
     * the invoice is issued immediately (status sent, due in seven days) with
     * the customer's place of supply resolved for GST.
     *
     * @param  OrderItem  $addonItem  The add-on's recurring order_items row.
     * @param  OrderItem|null  $setupFeeItem  The one-time setup row, when one was created.
     * @param  string|null  $notes  Invoice note override.
     */
    public function createAddonChargeInvoice(OrderItem $addonItem, ?OrderItem $setupFeeItem = null, ?string $notes = null): Invoice
    {
        $order = $addonItem->order;
        $fallbackCycle = (string) $order->billing_cycle;

        $chargeItems = [$addonItem];

        if ($setupFeeItem !== null) {
            $chargeItems[] = $setupFeeItem;
        }

        $items = array_map(fn (OrderItem $item) => $this->linePayload($item, $fallbackCycle), $chargeItems);

        return $this->createWithItems([
            'customer_id' => $order->customer_id,
            'order_id' => $addonItem->order_id,
            'amount' => round(array_sum(array_column($items, 'total')), 2),
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7)->toDateString(),
            'notes' => $notes ?? "Add-on charge — {$addonItem->product_name}",
        ], $items, $this->resolvePlaceOfSupply((int) $order->customer_id));
    }

    /**
     * One invoice-line payload for an order item, using the same
     * "{product_name} — {Cycle label}" description convention everywhere a
     * line is built (order invoice, add-on charge).
     *
     * @return array{description:string,quantity:int,unit_price:float,total:float,product_id:?int,config_options:?array}
     */
    private function linePayload(OrderItem $item, string $fallbackCycle): array
    {
        $lineCycle = $item->billing_cycle ?? $fallbackCycle;

        return [
            'description' => $item->product_name.' — '.ucfirst(str_replace('_', ' ', (string) $lineCycle)),
            'quantity' => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'total' => (float) $item->total,
            'product_id' => $item->product_id,
            // The configuration travels with the line, so the invoice can
            // show the RAM / storage / support it is charging for.
            'config_options' => $item->config_options,
        ];
    }

    /**
     * Mark an invoice paid by recording a completed payment.
     *
     * Port of InvoiceModel::markPaid L377-400: creates the payment, then sets
     * status 'paid' (with paid_at) when the completed-payment sum reaches the
     * total, otherwise 'partial'.
     *
     * @return int Payment id.
     */
    public function markPaid(int $id, float $amount, string $method, string $transactionId = ''): int
    {
        return DB::transaction(function () use ($id, $amount, $method, $transactionId) {
            $payment = Payment::create([
                'invoice_id' => $id,
                'amount' => $amount,
                'method' => $method,
                'transaction_id' => $transactionId,
                'status' => 'completed',
            ]);

            $invoice = Invoice::findOrFail($id);
            $paid = (float) Payment::where('invoice_id', $id)
                ->where('status', 'completed')
                ->sum('amount');

            if ($paid >= (float) $invoice->total) {
                $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            } else {
                $invoice->update(['status' => 'partial']);
            }

            // Keep the paid_amount column in sync with the payments ledger so
            // both reference mechanisms (SUM query vs paid_amount column) agree
            // — documented divergence from the reference markPaid.
            $invoice->update(['paid_amount' => $paid]);

            // Fully-settled invoices advance the linked order (pending → paid)
            // via the InvoicePaid listener — the billing → order bridge.
            if ($invoice->fresh()->isFullyPaid()) {
                InvoicePaid::dispatch($invoice);
            }

            return (int) $payment->id;
        });
    }

    /**
     * Record a payment against an invoice, handling partial/overpayment.
     *
     * Port of PaymentReconciliation::handlePartialPayment + handleOverpayment,
     * with the payment row created here as the single entry point:
     *  - partial payment → invoice status 'partial', paid_amount accumulated.
     *  - full payment     → status 'paid' + paid_at.
     *  - overpayment      → paid_amount clamped to total, status 'paid', and the
     *    excess credited: customers.credit += overpayment (reference) AND a
     *    customer_wallet ledger row (type 'credit', balance_type 'credit') so the
     *    wallet ledger reflects it.
     *
     * $pendingPaymentId settles a payment row that already exists rather than
     * creating a second one. Online gateways write a `pending` row when the
     * customer is sent to the gateway (it carries the gateway_id and the
     * purchase reference); when the money is later confirmed — by the customer
     * returning, or by the gateway's webhook — that same row is completed here.
     * Without it the confirmation would leave the pending row orphaned beside a
     * new completed one, and the two confirmation paths would each record their
     * own payment for the same money.
     *
     * @return array{invoice_id:int,payment_id:int,amount:float,status:string,previous_due:float,remaining_due:float,overpayment:float,credit_created:bool}
     */
    public function recordPayment(
        int $invoiceId,
        float $amount,
        string $method,
        string $transactionId = '',
        ?int $pendingPaymentId = null,
    ): array {
        return DB::transaction(function () use ($invoiceId, $amount, $method, $transactionId, $pendingPaymentId) {
            $invoice = Invoice::findOrFail($invoiceId);

            $total = (float) $invoice->total;
            $paidAmount = (float) ($invoice->paid_amount ?? 0);
            $previousDue = max(0.0, $total - $paidAmount);

            $overpayment = ($paidAmount + $amount) - $total;

            $payment = $this->settleOrCreatePayment(
                $invoiceId,
                $amount,
                $method,
                $transactionId,
                $pendingPaymentId,
            );

            // Overpayment: clamp paid_amount to total, mark paid, credit the excess.
            if ($overpayment > 0) {
                $invoice->update(['paid_amount' => $total, 'status' => 'paid', 'paid_at' => now()]);

                $creditCreated = false;
                if ($invoice->customer_id !== null && (int) $invoice->customer_id > 0) {
                    Customer::where('id', $invoice->customer_id)->increment('credit', $overpayment);
                    CustomerWallet::create([
                        'customer_id' => $invoice->customer_id,
                        'type' => 'credit',
                        'amount' => $overpayment,
                        'balance_type' => 'credit',
                        'description' => 'Overpayment credit on invoice '.$invoice->invoice_no,
                        'invoice_id' => $invoice->id,
                    ]);
                    $creditCreated = true;
                }

                // Overpayment settles the invoice → advance the linked order.
                if ($invoice->fresh()->isFullyPaid()) {
                    InvoicePaid::dispatch($invoice);
                }

                return [
                    'invoice_id' => $invoiceId,
                    'payment_id' => (int) $payment->id,
                    'amount' => $amount,
                    'status' => 'overpaid',
                    'previous_due' => $previousDue,
                    'remaining_due' => 0.0,
                    'overpayment' => $overpayment,
                    'credit_created' => $creditCreated,
                ];
            }

            // Partial or full payment (reference handlePartialPayment L133-164).
            $newPaid = $paidAmount + $amount;
            $remainingDue = max(0.0, $total - $newPaid);
            $newStatus = $remainingDue <= 0 ? 'paid' : 'partial';

            $invoice->update([
                'paid_amount' => $newPaid,
                'status' => $newStatus,
                'paid_at' => $newStatus === 'paid' ? now() : $invoice->paid_at,
            ]);

            // Full payment settles the invoice → advance the linked order.
            if ($invoice->fresh()->isFullyPaid()) {
                InvoicePaid::dispatch($invoice);
            }

            return [
                'invoice_id' => $invoiceId,
                'payment_id' => (int) $payment->id,
                'amount' => $amount,
                'status' => $newStatus,
                'previous_due' => $previousDue,
                'remaining_due' => $remainingDue,
                'overpayment' => 0.0,
                'credit_created' => false,
            ];
        });
    }

    /**
     * The completed payment row for this settlement: the pre-existing pending
     * gateway row when one was named and is still claimable, otherwise a new
     * one.
     *
     * The row is only reused when it belongs to this invoice and is still
     * `pending`. A caller naming an already-completed row would otherwise
     * double-count the money — the guard belongs here, next to the accounting,
     * rather than in each of the two gateway callbacks.
     */
    private function settleOrCreatePayment(
        int $invoiceId,
        float $amount,
        string $method,
        string $transactionId,
        ?int $pendingPaymentId,
    ): Payment {
        if ($pendingPaymentId !== null) {
            $pending = Payment::where('id', $pendingPaymentId)
                ->where('invoice_id', $invoiceId)
                ->where('status', 'pending')
                ->first();

            if ($pending !== null) {
                $pending->update([
                    'amount' => $amount,
                    'status' => 'completed',
                    'transaction_id' => $transactionId !== '' ? $transactionId : $pending->transaction_id,
                ]);

                return $pending;
            }
        }

        return Payment::create([
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'method' => $method,
            'transaction_id' => $transactionId,
            'status' => 'completed',
        ]);
    }

    /**
     * Generate recurring renewal invoices for active orders.
     *
     * WHMCS-style per-service billing: an order is a one-time transaction, and
     * each order item (the purchased product/service) renews on its OWN
     * billing cycle with its own amount and next-due date. The renewal pass:
     *
     *  - fetches active orders whose summary next_billing_date is due — where
     *    "due" means on or before today + the `renewal_invoice_days` window,
     *    boundary inclusive (0 = on the due date, the historical behaviour;
     *    up to 90 days early);
     *  - within each order, bills ONLY the items that are actually due (item
     *    cycle consumes months, item next_billing_date <= today + window, not
     *    already billed within its current cycle, total > 0, and the item's
     *    recurring-cycles limit is not exhausted) —
     *    one invoice per order with one line per due item; a due item with
     *    total <= 0 is never invoiced but still advances on its own schedule;
     *  - issues the invoice due on the EARLIEST due item's own next_billing_date
     *    (not today + 7), so generating early does not shift the due date;
     *  - advances each billed item's next_billing_date by ITS cycle months FROM
     *    ITS OWN due date (not from the run date) and bumps its
     *    billing_cycles_count, so an early-generation window never drifts the
     *    billing anniversary;
     *  - recomputes the order-level next_billing_date as the earliest
     *    remaining item date (null when no item is still recurring).
     *
     * A null item next_billing_date means "intentionally ended" (cancelled
     * add-on, exhausted cycle limit, elapsed fixed term) — such items are
     * never re-armed from the order summary. Only legacy items (created before
     * the per-item billing columns, billing_cycles_count NULL) fall back to
     * the order-level date.
     *
     * Legacy items created before the per-item billing columns existed have
     * NULL billing state and fall back to the order's billing_cycle and
     * next_billing_date (preserving the old order-level behaviour); once
     * billed they adopt their own schedule.
     *
     * @param  DateTimeInterface|null  $asOf  Reference date (defaults to today,
     *                                        Asia/Kolkata); the generation window
     *                                        and cycle advances are measured from
     *                                        this date.
     * @return array{invoices_generated:int,errors:int}
     */
    public function processRecurringBilling(?DateTimeInterface $asOf = null): array
    {
        $today = $asOf === null
            ? CarbonImmutable::today('Asia/Kolkata')
            : CarbonImmutable::parse($asOf)->setTimezone('Asia/Kolkata')->startOfDay();

        // Renewal invoice generation window (WHMCS "generate X days before
        // due"): 0 = bill on the due date, the historical behaviour.
        $generateDays = max(0, (int) AppSettings::get('renewal_invoice_days', '0'));
        $windowEnd = $today->addDays($generateDays);

        $orders = Order::query()
            ->where('status', 'active')
            ->whereNotNull('next_billing_date')
            ->whereDate('next_billing_date', '<=', $windowEnd->toDateString())
            ->with(['items.product', 'product', 'customer'])
            ->get();

        $invoicesGenerated = 0;
        $errors = 0;

        foreach ($orders as $order) {
            try {
                // Which of this order's items are due for renewal?
                $dueItems = [];
                $zeroDueItems = [];

                foreach ($order->items as $item) {
                    $cycle = $item->billing_cycle ?? $order->billing_cycle;
                    $cycleMonths = Order::CYCLE_MONTHS[$cycle] ?? 1;

                    // one_time (and any zero-month cycle) never renews.
                    if ($cycleMonths <= 0) {
                        continue;
                    }

                    // The item's own due date; legacy items (created before the
                    // per-item billing columns, counter NULL) fall back to the
                    // order's summary date. A null date on a NON-legacy item
                    // means the schedule was intentionally ended (cancel /
                    // cycle limit / fixed term) — never re-arm it from the
                    // order summary, or a cancelled add-on gets re-billed.
                    $dueDate = $item->next_billing_date
                        ?? ($item->billing_cycles_count === null ? $order->next_billing_date : null);

                    // Date-string comparison: a date-cast value is stored with a
                    // time component on SQLite, and a timezone-aware Carbon
                    // comparison excludes the window boundary. Comparing Y-m-d
                    // strings makes "due exactly N days early" inclusive on
                    // every driver.
                    if ($dueDate === null || CarbonImmutable::parse($dueDate)->toDateString() > $windowEnd->toDateString()) {
                        continue;
                    }

                    // Once-per-cycle guard: when the window is wider than the
                    // item's cycle, the advanced date is still inside the window
                    // on the next run. Skip an item already billed within its
                    // current cycle; legacy items (no last_billing_date) stay
                    // eligible, and a long-overdue item still catches up one
                    // period per run (last + cycle <= today).
                    if ($item->last_billing_date !== null
                        && CarbonImmutable::parse($item->last_billing_date)->addMonths($cycleMonths)->gt($today)) {
                        continue;
                    }

                    // Quantity-aware total: the renewal line must reflect the
                    // qty actually ordered, not a hardcoded unit.
                    $total = round((float) $item->unit_price * (int) ($item->quantity ?? 1), 2);

                    // Recurring cycles limit. New items carry a per-item
                    // snapshot (billing_cycles_count is set at creation); the
                    // item's own counter and snapshot are authoritative. Legacy
                    // items (counter NULL, created before the per-item billing
                    // migration) fall back to the product's current limit and
                    // the order's non-draft invoice count (the old mechanism).
                    $isLegacyItem = $item->billing_cycles_count === null;
                    $cycleLimit = $isLegacyItem
                        ? (int) ($item->product?->recurring_cycles_limit ?? 0)
                        : (int) $item->recurring_cycles_limit;

                    if ($cycleLimit > 0) {
                        $cyclesBilled = $isLegacyItem
                            ? $order->invoices()->where('status', '!=', Invoice::STATUS_DRAFT)->count()
                            : (int) $item->billing_cycles_count;

                        if ($cyclesBilled >= $cycleLimit) {
                            $item->update(['next_billing_date' => null]);

                            continue;
                        }
                    }

                    // Free / zero-value lines are never invoiced (a 0-value
                    // sent invoice is worse than none) — but they must not
                    // stay stuck due either: collect them so their schedule
                    // advances exactly like a billed item's, minus the
                    // invoice (review F7).
                    if ($total <= 0) {
                        $zeroDueItems[] = [
                            'item' => $item,
                            'cycleMonths' => $cycleMonths,
                            'advanceFrom' => $dueDate,
                        ];

                        continue;
                    }

                    $dueItems[] = ['item' => $item, 'cycle' => $cycle, 'cycleMonths' => $cycleMonths, 'total' => $total, 'dueDate' => $dueDate];
                }

                // Advance the schedule of every due-but-free item from its OWN
                // due date, so its billing anniversary is preserved without an
                // invoice and without an error.
                foreach ($zeroDueItems as $due) {
                    $item = $due['item'];
                    $item->update([
                        'next_billing_date' => CarbonImmutable::parse($due['advanceFrom'])->addMonths($due['cycleMonths'])->toDateString(),
                        'last_billing_date' => $today->toDateString(),
                        'billing_cycles_count' => $item->billing_cycles_count !== null
                            ? (int) $item->billing_cycles_count + 1
                            : null,
                    ]);
                }

                if ($dueItems === []) {
                    // No billable item — but a limit-exhausted item may have
                    // dropped the only remaining schedule, and a zero-value
                    // item has just advanced; keep the summary in sync.
                    $this->syncOrderSummary($order);

                    continue;
                }

                $invoiceAmount = round(array_sum(array_column($dueItems, 'total')), 2);
                $items = array_map(fn ($due) => [
                    'description' => $due['item']->product_name.' - '.ucfirst(str_replace('_', ' ', $due['cycle'])),
                    'quantity' => (int) $due['item']->quantity,
                    'unit_price' => (float) $due['item']->unit_price,
                    'total' => $due['total'],
                    // The product link drives per-product GST; without it a
                    // per_product-mode line would silently bill tax-free.
                    'product_id' => $due['item']->product_id,
                    // A renewal bills the same configuration as the original
                    // order, so the renewal invoice states it too.
                    'config_options' => $due['item']->config_options,
                ], $dueItems);

                // RENEWAL-IGST FIX (decisions.md #6): the reference passed a null
                // customer state → every renewal was inter-state → IGST. Resolve
                // the place of supply first (customer state, else the company
                // state as intra-state fallback) so renewals never default to
                // the higher IGST rate.
                $customerStateCode = $this->resolvePlaceOfSupply((int) $order->customer_id);

                // The invoice is due on the earliest due item's OWN date: an
                // early-generation window must not shift the due date.
                $earliestDueDate = null;
                foreach ($dueItems as $due) {
                    $candidate = CarbonImmutable::parse($due['dueDate']);
                    if ($earliestDueDate === null || $candidate->lt($earliestDueDate)) {
                        $earliestDueDate = $candidate;
                    }
                }

                $this->createWithItems([
                    'customer_id' => $order->customer_id,
                    'order_id' => $order->id,
                    'amount' => $invoiceAmount,
                    'status' => Invoice::STATUS_SENT,
                    'due_date' => $earliestDueDate->toDateString(),
                    'notes' => 'Auto-generated renewal invoice',
                ], $items, $customerStateCode);

                // Advance each billed item on ITS cycle FROM ITS OWN due date
                // (not the run date) so an early-generation window never drifts
                // the billing anniversary; the order summary date becomes the
                // earliest remaining item date.
                foreach ($dueItems as $due) {
                    $item = $due['item'];
                    $item->update([
                        'next_billing_date' => CarbonImmutable::parse($due['dueDate'])->addMonths($due['cycleMonths'])->toDateString(),
                        'last_billing_date' => $today->toDateString(),
                        'billing_cycles_count' => $item->billing_cycles_count !== null
                            ? (int) $item->billing_cycles_count + 1
                            : null,
                    ]);
                }

                $this->syncOrderSummary($order, $today->toDateString());

                $invoicesGenerated++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        return ['invoices_generated' => $invoicesGenerated, 'errors' => $errors];
    }

    /**
     * Keep the order-level billing summary in sync with its items: the order's
     * next_billing_date is the earliest item next date (null when no item is
     * still recurring), and last_billing_date records the most recent billing.
     */
    public function syncOrderSummary(Order $order, ?string $lastBillingDate = null): void
    {
        $nextDates = $order->items()
            ->whereNotNull('next_billing_date')
            ->pluck('next_billing_date');

        $attributes = [
            'next_billing_date' => $nextDates->isNotEmpty()
                ? $nextDates->min()->format('Y-m-d')
                : null,
        ];

        if ($lastBillingDate !== null) {
            $attributes['last_billing_date'] = $lastBillingDate;
        }

        $order->update($attributes);
    }

    /**
     * Resolve a customer's state code for intra/inter-state GST decisions.
     *
     * Source: customers.state_code (added by the billing migration — mirrors the
     * reference's `SELECT state FROM customers` in ApiRoutes L960). Returns null
     * when unknown; callers fall back to the company state code (intra-state).
     *
     * Normalized through GstStateCodes so the value is always in the same
     * vocabulary as `gst_settings.state_code`. That is belt-and-braces on top of
     * the normalizing migration: a row written by an older release, or by an
     * import, that still holds 'WB' resolves to '19' here instead of silently
     * failing the intra-state comparison and billing the sale as inter-state.
     */
    public function resolveCustomerStateCode(int $customerId): ?string
    {
        $customer = Customer::find($customerId);

        return $customer === null ? null : GstStateCodes::normalize($customer->state_code);
    }

    /**
     * Resolve the place of supply for an invoice: the normalized customer
     * state code, else the company state code (intra-state fallback), else
     * '27'. Never null, so callers never default to the higher IGST rate.
     */
    public function resolvePlaceOfSupply(int $customerId): string
    {
        $customerCode = $this->resolveCustomerStateCode($customerId);

        if ($customerCode !== null && $customerCode !== '') {
            return $customerCode;
        }

        $companyCode = (string) (GstTaxService::loadSettings(GstSetting::find(1))['state_code'] ?? '');

        return $companyCode !== '' ? $companyCode : '27';
    }

    /**
     * Auto-terminate services whose fixed term has elapsed (per-service model).
     *
     * Each order item (the purchased product/service) has its OWN fixed term,
     * taken from its product's auto_terminate_value + auto_terminate_unit
     * ("30 Days", "12 Months", "2 Years"; 0 = no term). The term runs from the
     * order's activation — the earliest order_status_history row that moved
     * the order to 'active' (all services on the order activate together).
     *
     * When an item's term elapses, THAT item's recurring billing ends (its
     * next_billing_date is cleared — the same end-state the recurring-cycles
     * limit produces), independent of its siblings. The order itself is
     * terminated through the guarded state machine only when nothing remains
     * on a recurring schedule — i.e. single-service orders (and orders whose
     * every service has ended) keep the previous behaviour; orders with
     * sibling services still running stay active.
     *
     * @param  DateTimeInterface|null  $asOf  Reference date (defaults to today,
     *                                        Asia/Kolkata).
     * @return array{terminated:int,errors:int}
     */
    public function processAutoTerminations(?DateTimeInterface $asOf = null): array
    {
        $today = $asOf === null
            ? CarbonImmutable::today('Asia/Kolkata')
            : CarbonImmutable::parse($asOf)->setTimezone('Asia/Kolkata')->startOfDay();

        $orders = Order::query()
            ->where('status', Order::STATUS_ACTIVE)
            ->whereHas('items.product', fn ($query) => $query->where('auto_terminate_value', '>', 0))
            ->with(['items.product', 'product'])
            ->get();

        $terminated = 0;
        $errors = 0;

        foreach ($orders as $order) {
            try {
                $activatedAt = $order->statusHistory()
                    ->where('to_status', Order::STATUS_ACTIVE)
                    ->orderBy('id')
                    ->value('created_at');

                if ($activatedAt === null) {
                    continue; // legacy order without an activation audit anchor
                }

                // End the billing schedule of every item whose product-defined
                // fixed term has elapsed; sibling items are left untouched.
                $endedItem = false;

                foreach ($order->items as $item) {
                    $termValue = (int) ($item->product?->auto_terminate_value ?? 0);
                    if ($termValue <= 0) {
                        continue;
                    }

                    $termEnd = match ((string) ($item->product->auto_terminate_unit ?? 'days')) {
                        'months' => CarbonImmutable::parse($activatedAt)->addMonths($termValue),
                        'years' => CarbonImmutable::parse($activatedAt)->addYears($termValue),
                        default => CarbonImmutable::parse($activatedAt)->addDays($termValue),
                    };

                    if ($termEnd->startOfDay()->lte($today)) {
                        $item->update(['next_billing_date' => null]);
                        $endedItem = true;
                    }
                }

                // Recompute the order summary (earliest remaining item date).
                $this->syncOrderSummary($order);
                $order->refresh();

                // Terminate the order only when no service remains recurring.
                if ($endedItem && $order->next_billing_date === null) {
                    $this->orderService->terminate($order, 'Auto-terminated: all services reached their fixed term.');

                    $terminated++;
                }
            } catch (\Throwable) {
                $errors++;
            }
        }

        return ['terminated' => $terminated, 'errors' => $errors];
    }
}
