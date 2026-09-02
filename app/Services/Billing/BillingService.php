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
use App\Services\OrderService;
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
    public function __construct(private readonly OrderService $orderService)
    {
    }
    /**
     * Generate the next invoice number: INV-{year}-{seq} padded to 5.
     * Port of InvoiceModel::generateNumber L18-26.
     */
    public function generateNumber(): string
    {
        $year = date('Y');
        $seq = Invoice::whereYear('created_at', $year)->count() + 1;

        return sprintf('INV-%s-%s', $year, str_pad((string) $seq, 5, '0', STR_PAD_LEFT));
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

        return DB::transaction(function () use ($invoiceData, $computed, $amount, $discount, $invoiceTax) {
            $invoice = Invoice::create(array_merge($invoiceData, [
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
            ]));

            foreach ($computed['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'],
                    'total' => $item['total'],
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
        $settings = GstTaxService::loadSettings(GstSetting::find(1));
        $computed = GstTaxService::computeInvoiceTaxes($items, $settings, $customerStateCode);

        $amount = (float) ($invoiceData['amount'] ?? 0);
        $discount = (float) ($invoiceData['discount'] ?? 0);
        $invoiceTax = $computed['invoice'];

        DB::transaction(function () use ($invoice, $invoiceData, $computed, $amount, $discount, $invoiceTax) {
            $invoice->update(array_merge($invoiceData, [
                'tax' => $invoiceTax['tax'],
                'gst_enabled' => $invoiceTax['gst_enabled'],
                'cgst_rate' => $invoiceTax['cgst_rate'],
                'cgst_amount' => $invoiceTax['cgst_amount'],
                'sgst_rate' => $invoiceTax['sgst_rate'],
                'sgst_amount' => $invoiceTax['sgst_amount'],
                'igst_rate' => $invoiceTax['igst_rate'],
                'igst_amount' => $invoiceTax['igst_amount'],
                'total' => round($amount + $invoiceTax['tax'] - $discount, 2),
            ]));

            $invoice->items()->delete();

            foreach ($computed['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'],
                    'total' => $item['total'],
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
        $items = $order->items->map(function (OrderItem $item) use ($order) {
            $lineCycle = $item->billing_cycle ?? $order->billing_cycle;

            return [
                'description' => $item->product_name.' — '.ucfirst(str_replace('_', ' ', (string) $lineCycle)),
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
                'product_id' => $item->product_id,
            ];
        })->all();

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
     * @return array{invoice_id:int,payment_id:int,amount:float,status:string,previous_due:float,remaining_due:float,overpayment:float,credit_created:bool}
     */
    public function recordPayment(int $invoiceId, float $amount, string $method, string $transactionId = ''): array
    {
        return DB::transaction(function () use ($invoiceId, $amount, $method, $transactionId) {
            $invoice = Invoice::findOrFail($invoiceId);

            $total = (float) $invoice->total;
            $paidAmount = (float) ($invoice->paid_amount ?? 0);
            $previousDue = max(0.0, $total - $paidAmount);

            $overpayment = ($paidAmount + $amount) - $total;

            $payment = Payment::create([
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'method' => $method,
                'transaction_id' => $transactionId,
                'status' => 'completed',
            ]);

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
     * Generate recurring renewal invoices for active orders.
     *
     * WHMCS-style per-service billing: an order is a one-time transaction, and
     * each order item (the purchased product/service) renews on its OWN
     * billing cycle with its own amount and next-due date. The renewal pass:
     *
     *  - fetches active orders whose summary next_billing_date is due (the
     *    order-level date is the earliest item date, kept as a summary for the
     *    orders list);
     *  - within each order, bills ONLY the items that are actually due (item
     *    cycle consumes months, item next_billing_date <= today, total > 0,
     *    and the item's recurring-cycles limit is not exhausted) — one invoice
     *    per order with one line per due item;
     *  - advances each billed item's next_billing_date by ITS cycle months and
     *    bumps its billing_cycles_count;
     *  - recomputes the order-level next_billing_date as the earliest
     *    remaining item date (null when no item is still recurring).
     *
     * Legacy items created before the per-item billing columns existed have
     * NULL billing state and fall back to the order's billing_cycle and
     * next_billing_date (preserving the old order-level behaviour); once
     * billed they adopt their own schedule.
     *
     * @param  DateTimeInterface|null  $asOf  Reference date (defaults to today,
     *                                       Asia/Kolkata); due_date and the next
     *                                       cycle advance from this date.
     *
     * @return array{invoices_generated:int,errors:int}
     */
    public function processRecurringBilling(?DateTimeInterface $asOf = null): array
    {
        $today = $asOf === null
            ? CarbonImmutable::today('Asia/Kolkata')
            : CarbonImmutable::parse($asOf)->setTimezone('Asia/Kolkata')->startOfDay();

        $gstSettings = GstTaxService::loadSettings(GstSetting::find(1));
        $companyStateCode = (string) ($gstSettings['state_code'] ?? '');

        $orders = Order::query()
            ->where('status', 'active')
            ->whereNotNull('next_billing_date')
            ->where('next_billing_date', '<=', $today->toDateString())
            ->with(['items.product', 'product', 'customer'])
            ->get();

        $invoicesGenerated = 0;
        $errors = 0;

        foreach ($orders as $order) {
            try {
                // Which of this order's items are due for renewal?
                $dueItems = [];

                foreach ($order->items as $item) {
                    $cycle = $item->billing_cycle ?? $order->billing_cycle;
                    $cycleMonths = Order::CYCLE_MONTHS[$cycle] ?? 1;

                    // one_time (and any zero-month cycle) never renews.
                    if ($cycleMonths <= 0) {
                        continue;
                    }

                    // The item's own due date; legacy items fall back to the
                    // order's summary date.
                    $dueDate = $item->next_billing_date ?? $order->next_billing_date;
                    if ($dueDate === null || CarbonImmutable::parse($dueDate) > $today) {
                        continue;
                    }

                    // Quantity-aware total: the renewal line must reflect the
                    // qty actually ordered, not a hardcoded unit.
                    $total = round((float) $item->unit_price * (int) ($item->quantity ?? 1), 2);

                    // Free / zero-value lines produce nothing — billing nothing
                    // for a null total would create a 0-value sent invoice.
                    if ($total <= 0) {
                        $errors++;

                        continue;
                    }

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

                    $dueItems[] = ['item' => $item, 'cycle' => $cycle, 'cycleMonths' => $cycleMonths, 'total' => $total];
                }

                if ($dueItems === []) {
                    // No item due (or every due item was skipped) — but a
                    // skipped limit-exhausted item may have dropped the only
                    // remaining schedule; keep the summary in sync.
                    $this->syncOrderSummary($order);

                    continue;
                }

                $invoiceAmount = round(array_sum(array_column($dueItems, 'total')), 2);
                $items = array_map(fn ($due) => [
                    'description' => $due['item']->product_name.' - '.ucfirst(str_replace('_', ' ', $due['cycle'])),
                    'quantity' => (int) $due['item']->quantity,
                    'unit_price' => (float) $due['item']->unit_price,
                    'total' => $due['total'],
                ], $dueItems);

                // RENEWAL-IGST FIX (decisions.md #6): the reference passed a null
                // customer state → every renewal was inter-state → IGST. Resolve
                // the customer's state first and fall back to the company state
                // (intra-state) when it is unknown, so renewals never default to
                // the higher IGST rate.
                $customerStateCode = $this->resolveCustomerStateCode((int) $order->customer_id) ?? $companyStateCode;

                $this->createWithItems([
                    'customer_id' => $order->customer_id,
                    'order_id' => $order->id,
                    'amount' => $invoiceAmount,
                    'status' => Invoice::STATUS_SENT,
                    'due_date' => $today->addDays(7)->toDateString(),
                    'notes' => 'Auto-generated renewal invoice',
                ], $items, $customerStateCode);

                // Advance each billed item on ITS cycle; the order summary
                // date becomes the earliest remaining item date.
                foreach ($dueItems as $due) {
                    $item = $due['item'];
                    $item->update([
                        'next_billing_date' => $today->addMonths($due['cycleMonths'])->toDateString(),
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
    private function syncOrderSummary(Order $order, ?string $lastBillingDate = null): void
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
     */
    public function resolveCustomerStateCode(int $customerId): ?string
    {
        $customer = Customer::find($customerId);

        if (! $customer || $customer->state_code === null || $customer->state_code === '') {
            return null;
        }

        return strtoupper((string) $customer->state_code);
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
     *                                       Asia/Kolkata).
     *
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
