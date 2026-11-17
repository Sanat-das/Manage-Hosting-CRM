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
use App\Services\InvoiceEmailService;
use App\Services\OrderNumberService;
use App\Services\OrderService;
use App\Support\AppSettings;
use App\Support\GstStateCodes;
use App\Support\Logging\AppLog;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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

        $amount = (float) ($invoiceData['amount'] ?? 0);

        // A discount larger than the amount would make the total negative
        // (total = amount + tax - discount), so it is capped here as well as
        // in the form: this service is called from paths that never validate.
        $discount = min(max(0.0, (float) ($invoiceData['discount'] ?? 0)), max(0.0, $amount));

        // The tax base is the DISCOUNTED value: the invoice discount is split
        // across the lines pro-rata and every line is taxed on what it bills
        // after its share of the discount.
        $computed = GstTaxService::computeInvoiceTaxes(
            $this->applyDiscountToTaxBase($items, $discount),
            $settings,
            $customerStateCode
        );

        $invoiceTax = $computed['invoice'];

        return DB::transaction(function () use ($invoiceData, $computed, $amount, $discount, $invoiceTax, $customerStateCode) {
            $attributes = array_merge($invoiceData, [
                'invoice_no' => $this->generateNumber(),
                'tax' => $invoiceTax['tax'],
                'tax_rate' => $this->effectiveTaxRate((float) $invoiceTax['tax'], $amount - $discount),
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
                    // The order line this period bills, when the caller built
                    // the line from one (renewal, order and add-on lines).
                    'order_item_id' => $item['order_item_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'],
                    // The line bills its GROSS total; the discount is an
                    // invoice-level reduction, applied once on the header.
                    'total' => $item['gross_total'] ?? $item['total'],
                    // The billing period this line billed, when it advanced a
                    // schedule (renewal lines). Null on hand-written lines.
                    'billed_due_date' => $item['billed_due_date'] ?? null,
                    'billed_cycle_months' => $item['billed_cycle_months'] ?? null,
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

        $amount = (float) ($invoiceData['amount'] ?? 0);
        $discount = min(max(0.0, (float) ($invoiceData['discount'] ?? 0)), max(0.0, $amount));

        // The tax base is the DISCOUNTED value: the invoice discount is split
        // across the lines pro-rata and every line is taxed on what it bills
        // after its share of the discount.
        $computed = GstTaxService::computeInvoiceTaxes(
            $this->applyDiscountToTaxBase($items, $discount),
            $settings,
            $customerStateCode
        );

        $invoiceTax = $computed['invoice'];

        DB::transaction(function () use ($invoice, $invoiceData, $computed, $amount, $discount, $invoiceTax, $customerStateCode) {
            $attributes = array_merge($invoiceData, [
                'tax' => $invoiceTax['tax'],
                'tax_rate' => $this->effectiveTaxRate((float) $invoiceTax['tax'], $amount - $discount),
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
                    'order_item_id' => $item['order_item_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'],
                    // The line bills its GROSS total; the discount is an
                    // invoice-level reduction, applied once on the header.
                    'total' => $item['gross_total'] ?? $item['total'],
                    // The billing period this line billed, when it advanced a
                    // schedule (renewal lines). Null on hand-written lines.
                    'billed_due_date' => $item['billed_due_date'] ?? null,
                    'billed_cycle_months' => $item['billed_cycle_months'] ?? null,
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
     * Split an invoice-level discount across the lines, pro-rata by line
     * total, so the tax base is the DISCOUNTED value.
     *
     * The business rule: a trade discount shown on the invoice reduces the
     * taxable value. Each line keeps its GROSS total (what it bills); only
     * the value it is taxed on drops by its share of the discount. The last
     * line absorbs the rounding remainder, so the shares sum to the discount
     * exactly.
     *
     * @param  array<int,array>  $items  Lines with a numeric 'total'.
     * @return array<int,array> Lines with 'gross_total' (what the line bills)
     *                          and 'total' (the taxable value).
     */
    private function applyDiscountToTaxBase(array $items, float $discount): array
    {
        $grossTotal = 0.0;

        foreach ($items as $item) {
            $grossTotal += (float) ($item['total'] ?? 0);
        }

        if ($discount <= 0.0 || $grossTotal <= 0.0) {
            return array_map(
                fn (array $item) => array_merge($item, ['gross_total' => (float) ($item['total'] ?? 0)]),
                $items
            );
        }

        $allocated = 0.0;
        $lastKey = array_key_last($items);
        $result = [];

        foreach ($items as $key => $item) {
            $gross = (float) ($item['total'] ?? 0);

            $share = $key === $lastKey
                ? round($discount - $allocated, 2)
                : round($discount * ($gross / $grossTotal), 2);

            $allocated = round($allocated + $share, 2);

            $result[$key] = array_merge($item, [
                'gross_total' => $gross,
                'total' => round($gross - $share, 2),
            ]);
        }

        return $result;
    }

    /**
     * The effective tax rate on the taxable base (net of the discount), as a
     * percentage. Zero when there is nothing to tax.
     */
    private function effectiveTaxRate(float $tax, float $taxableBase): float
    {
        return $taxableBase > 0.0 ? round($tax / $taxableBase * 100, 2) : 0.0;
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
                // only fills the gaps. The billed period rides along, so
                // editing a renewal invoice does not lose the link to the
                // schedule its lines advanced.
                $item = array_merge($item, [
                    'product_id' => $item['product_id'] ?? $match->product_id,
                    'order_item_id' => $item['order_item_id'] ?? $match->order_item_id,
                    'config_options' => $item['config_options'] ?? $match->config_options,
                    'billed_due_date' => $item['billed_due_date'] ?? $match->billed_due_date?->toDateString(),
                    'billed_cycle_months' => $item['billed_cycle_months'] ?? $match->billed_cycle_months,
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
     * This is the ORDER-TIME path: the order is still pending and its items
     * carry no schedule yet, so every line is billed without a billing period
     * and no pointer moves. A manually raised invoice against a live service
     * is a different thing and goes through createInvoiceForNextPeriod().
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
     * Create the invoice for a manually raised billing of an order's NEXT
     * recurring period — the admin "Invoice Selected Items" flow.
     *
     * createInvoiceForOrder() is the other path: the order-time draft, raised
     * while the order is still pending and its items carry no schedule yet.
     * That invoice bills every item without a period and moves nothing.
     *
     * This one bills a LIVE schedule, so it behaves like a hand-raised
     * renewal: every live-recurring line carries the period it bills
     * (billed_due_date = the item's next_invoice_date, billed_cycle_months =
     * the item's cycle months) and the generation pointer advances past that
     * period, exactly as processRecurringBilling() does. The pay-by date
     * (next_billing_date) does NOT move here — only the money moves it,
     * through advanceBillingSchedule() once the invoice is settled.
     *
     * An item that is not live-recurring (no next_invoice_date, or a one-time
     * cycle) is billed exactly as it is today: no period on its line, and no
     * pointer moved.
     *
     * @param  string|null  $notes  Invoice note override; defaults to the
     *                              standard "Invoice for order …" convention.
     */
    public function createInvoiceForNextPeriod(Order $order, ?string $notes = null): Invoice
    {
        $productName = $order->product?->name ?? 'Order';

        $items = $order->items
            ->map(fn (OrderItem $item) => $this->linePayload($item, (string) $order->billing_cycle))
            ->all();

        return $this->createPeriodInvoice($order, $items, [
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'amount' => (float) $order->total,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
            'notes' => $notes ?? "Invoice for order {$order->order_number} — {$productName} ({$order->billing_cycle})",
        ], $this->resolveCustomerStateCode((int) $order->customer_id));
    }

    /**
     * Create the invoice for a manually raised billing of selected add-on
     * lines — the admin "Invoice Selected Items" flow, add-on branch.
     *
     * The period semantics are the ones of createInvoiceForNextPeriod(): each
     * live-recurring add-on line carries the period it bills and its
     * generation pointer advances past that period, while the pay-by date
     * waits for the money.
     *
     * @param  iterable<int, OrderItem>  $addonItems  The add-on lines to bill.
     * @param  string|null  $notes  Invoice note override.
     */
    public function createAddonInvoiceForNextPeriod(Order $order, iterable $addonItems, ?string $notes = null): Invoice
    {
        $fallbackCycle = (string) $order->billing_cycle;

        $items = [];

        foreach ($addonItems as $addonItem) {
            $items[] = $this->linePayload($addonItem, $fallbackCycle);
        }

        return $this->createPeriodInvoice($order, $items, [
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'amount' => round(array_sum(array_column($items, 'total')), 2),
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7)->toDateString(),
            'notes' => $notes ?? "Add-on invoice for order {$order->order_number}",
        ], $this->resolveCustomerStateCode((int) $order->customer_id));
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
     * The recurring line carries the add-on's FIRST period — the one the
     * attach starts — so the charge is a period like any other and the
     * duplicate-billing net can see it. The setup fee is a one-time line with
     * no period behind it and stays period-less.
     *
     * Nothing moves here: the add-on's schedule is already seeded at attach
     * (today + cycle), so paying the charge leaves both pointers where they
     * are — advanceBillingSchedule() finds the pay-by date already at the end
     * of the period it bills.
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

        $period = $this->addonFirstPeriod($addonItem);

        if ($period !== null) {
            $items[0] = array_merge($items[0], [
                'billed_due_date' => $period['start'],
                'billed_cycle_months' => $period['months'],
            ]);
        }

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
     * @return array{description:string,quantity:int,unit_price:float,total:float,product_id:?int,order_item_id:int,config_options:?array}
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
            // The order line this invoice bills; the schedule rewind and the
            // paid-invoice advance both resolve lines back through it.
            'order_item_id' => (int) $item->id,
            // The configuration travels with the line, so the invoice can
            // show the RAM / storage / support it is charging for.
            'config_options' => $item->config_options,
        ];
    }

    /**
     * Create an invoice that bills the next recurring period of the given
     * order lines, and consume that period in the generation pointer.
     *
     * The invoice and the pointer advance are one unit of money-truth: they
     * are committed together, so a crash can never leave an issued invoice
     * whose service is still due — which would bill the same period again on
     * the next renewal run.
     *
     * @param  array<int, array>  $items  Line payloads; each carries the
     *                                    'order_item_id' it bills.
     * @param  array<string, mixed>  $invoiceData  The invoice header.
     */
    private function createPeriodInvoice(Order $order, array $items, array $invoiceData, ?string $customerStateCode): Invoice
    {
        $periods = $this->periodsFor($order, $items);

        $items = array_map(static function (array $item) use ($periods): array {
            $period = $periods[(int) ($item['order_item_id'] ?? 0)] ?? null;

            if ($period === null) {
                return $item;
            }

            return array_merge($item, [
                'billed_due_date' => $period['start'],
                'billed_cycle_months' => $period['months'],
            ]);
        }, $items);

        return DB::transaction(function () use ($order, $items, $invoiceData, $customerStateCode, $periods): Invoice {
            $invoice = $this->createWithItems($invoiceData, $items, $customerStateCode);

            foreach ($periods as $orderItemId => $period) {
                $this->consumeBilledPeriod($order, (int) $orderItemId, $period);
            }

            $order->refresh();

            $this->syncOrderSummary($order);

            return $invoice;
        });
    }

    /**
     * The billing period each line of a manual invoice bills, keyed by the
     * order line it bills.
     *
     * Only a live-recurring item has a period: one that still has a
     * generation date (its schedule has not ended) and a cycle longer than
     * zero months. Every other item — a pending order's line, a one-time
     * charge, an add-on whose schedule ended — is billed without a period
     * and moves no pointer.
     *
     * @param  array<int, array>  $items
     * @return array<int, array{start:string,months:int}>
     */
    private function periodsFor(Order $order, array $items): array
    {
        $periods = [];

        foreach ($items as $item) {
            $orderItemId = (int) ($item['order_item_id'] ?? 0);

            if ($orderItemId <= 0 || isset($periods[$orderItemId])) {
                continue;
            }

            $orderItem = $order->items()->whereKey($orderItemId)->first();

            if ($orderItem === null) {
                continue;
            }

            $period = $this->nextPeriodFor($order, $orderItem);

            if ($period !== null) {
                $periods[$orderItemId] = $period;
            }
        }

        return $periods;
    }

    /**
     * The period a live-recurring item's next invoice covers: the item's own
     * generation date and the cycle that period advances by.
     *
     * @return array{start:string,months:int}|null Null when the item does not
     *                                             renew (no schedule, or a
     *                                             one-time cycle).
     */
    private function nextPeriodFor(Order $order, OrderItem $item): ?array
    {
        if ($item->next_invoice_date === null) {
            return null;
        }

        $months = Order::CYCLE_MONTHS[$item->billing_cycle ?? $order->billing_cycle] ?? 1;

        if ($months <= 0) {
            return null;
        }

        return [
            'start' => $item->next_invoice_date->toDateString(),
            'months' => $months,
        ];
    }

    /**
     * Move a billed item's generation pointer past the period it billed.
     *
     * The pointer advances from the period's own start by the item's own
     * cycle, so raising the invoice late never drifts the billing
     * anniversary. The pay-by date (next_billing_date) is NOT touched here:
     * it moves when the money arrives, through advanceBillingSchedule().
     *
     * The cycle counter follows the pointer, because the period is consumed:
     * a void hands it back (rewindBillingSchedule), so the two must move
     * together. The advance is max-style — a pointer that already moved
     * further on is never pulled back, so raising the same period twice can
     * never rewind a schedule.
     *
     * @param  array{start:string,months:int}  $period
     */
    private function consumeBilledPeriod(Order $order, int $orderItemId, array $period): void
    {
        $item = $order->items()->whereKey($orderItemId)->first();

        if ($item === null) {
            return;
        }

        $advancedTo = CarbonImmutable::parse($period['start'])->addMonths($period['months'])->toDateString();
        $current = $item->next_invoice_date?->toDateString();

        $next = $current === null ? $advancedTo : max($current, $advancedTo);

        if ($next === $current) {
            return;
        }

        $item->update([
            'next_invoice_date' => $next,
            'billing_cycles_count' => $item->billing_cycles_count !== null
                ? (int) $item->billing_cycles_count + 1
                : null,
        ]);
    }

    /**
     * The period an add-on charge invoice bills: the add-on's FIRST period.
     *
     * The add-on's schedule is seeded at attach to the start of the period
     * AFTER the one the charge bills (today + cycle), so the billed period
     * started one cycle earlier — at the attach itself. A one-time add-on
     * has no period at all.
     *
     * @return array{start:string,months:int}|null
     */
    private function addonFirstPeriod(OrderItem $addonItem): ?array
    {
        $months = Order::CYCLE_MONTHS[$addonItem->billing_cycle] ?? 1;

        if ($months <= 0) {
            return null;
        }

        $scheduleStart = $addonItem->next_invoice_date ?? $addonItem->next_billing_date;

        $start = $scheduleStart !== null
            ? CarbonImmutable::parse($scheduleStart)->subMonths($months)->toDateString()
            : CarbonImmutable::today('Asia/Kolkata')->toDateString();

        return ['start' => $start, 'months' => $months];
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
     * billing cycle with its own amount and next-due date.
     *
     * Two pointers drive each service. `next_invoice_date` is the date the next
     * invoice is generated for: it advances here, at generation, so the same
     * period is never billed twice. `next_billing_date` is the date the
     * customer must pay by: it does NOT move here — only a settled invoice
     * advances it (see advanceBillingSchedule), so an unpaid period never reads
     * as collected.
     *
     * The renewal pass:
     *
     *  - fetches active orders whose summary next_invoice_date is due — where
     *    "due" means on or before today + the `renewal_invoice_days` window,
     *    boundary inclusive (0 = on the due date, the historical behaviour;
     *    up to 90 days early);
     *  - within each order, bills ONLY the items that are actually due (item
     *    cycle consumes months, item next_invoice_date <= today + window, no
     *    open invoice for the same period, not already billed within its
     *    current cycle, total > 0, and the item's recurring-cycles limit is
     *    not exhausted) —
     *    one invoice per order with one line per due item; a due item with
     *    total <= 0 is never invoiced but still advances on its own schedule;
     *  - issues the invoice due on the EARLIEST due item's own next_invoice_date
     *    (not today + 7), so generating early does not shift the due date;
     *  - advances each billed item's next_invoice_date by ITS cycle months FROM
     *    ITS OWN due date (not from the run date) and bumps its
     *    billing_cycles_count, so an early-generation window never drifts the
     *    billing anniversary; next_billing_date stays where it is until the
     *    invoice is paid;
     *  - recomputes the order summary: next_invoice_date as the earliest
     *    remaining item invoice date and next_billing_date as the earliest
     *    item pay-by date (null when no item is still recurring);
     *  - commits the invoice and the schedule advance in ONE transaction, so a
     *    crash can never leave an issued renewal invoice whose items are still
     *    due — which would bill the same period again on the next run;
     *  - counts every committed invoice in invoices_generated immediately after
     *    that commit, before the post-commit steps, so a failure there can never
     *    hide an invoice that was in fact generated;
     *  - runs the post-commit steps after that commit, each isolated: a
     *    superseded unpaid upgrade request that fails to cancel is logged at
     *    warning and is NOT an error (internal bookkeeping, not a client
     *    deliverable), while a renewal email that is not dispatched — a false
     *    return or a throw — is logged at error and counted in errors, because
     *    a renewal invoice the client never receives is a failed deliverable
     *    even when the money row is correct; no post-commit failure ever rolls
     *    back the invoice, alters its status, rewinds the schedule, or aborts
     *    the run.
     *
     * A null item next_invoice_date means "intentionally ended" (cancelled
     * add-on, exhausted cycle limit, elapsed fixed term) — such items are
     * never re-armed from the order summary. Only legacy items (created before
     * the per-item billing columns, billing_cycles_count NULL) fall back to
     * the order-level date.
     *
     * Legacy items created before the per-item billing columns existed have
     * NULL billing state and fall back to the order's billing_cycle and
     * next_invoice_date (its next_billing_date when that is null too),
     * preserving the old order-level behaviour; once billed they adopt their
     * own schedule.
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
            ->whereNotNull('next_invoice_date')
            ->whereDate('next_invoice_date', '<=', $windowEnd->toDateString())
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

                    // The item's own generation date; legacy items (created
                    // before the per-item billing columns, counter NULL) fall
                    // back to the order's summary date, which on a legacy row
                    // may only ever have had next_billing_date. A null date on
                    // a NON-legacy item means the schedule was intentionally
                    // ended (cancel / cycle limit / fixed term) — never re-arm
                    // it from the order summary, or a cancelled add-on gets
                    // re-billed.
                    $dueDate = $item->next_invoice_date
                        ?? ($item->billing_cycles_count === null
                            ? ($order->next_invoice_date ?? $order->next_billing_date)
                            : null);

                    // Date-string comparison: a date-cast value is stored with a
                    // time component on SQLite, and a timezone-aware Carbon
                    // comparison excludes the window boundary. Comparing Y-m-d
                    // strings makes "due exactly N days early" inclusive on
                    // every driver.
                    if ($dueDate === null || CarbonImmutable::parse($dueDate)->toDateString() > $windowEnd->toDateString()) {
                        continue;
                    }

                    // Duplicate-billing net: a period that already has an open
                    // invoice must not be billed again, whatever re-armed the
                    // item's pointer. The invoice line records the period it
                    // bills in billed_due_date, and — for lines created since
                    // lines carry their own order_item_id — which line it belongs
                    // to. A legacy line carries no order_item_id, so it still
                    // answers for the whole order on that date; a linked line only
                    // blocks its own item.
                    $periodDue = CarbonImmutable::parse($dueDate)->toDateString();
                    $periodAlreadyOpen = $order->invoices()
                        ->whereIn('status', [Invoice::STATUS_SENT, Invoice::STATUS_OVERDUE, Invoice::STATUS_PARTIAL])
                        ->whereHas('items', fn ($query) => $query
                            ->whereDate('billed_due_date', $periodDue)
                            ->where(fn ($line) => $line
                                ->where('order_item_id', $item->id)
                                ->orWhereNull('order_item_id')))
                        ->exists();

                    if ($periodAlreadyOpen) {
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
                            $item->update(['next_invoice_date' => null]);

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
                    $advancedTo = CarbonImmutable::parse($due['advanceFrom'])->addMonths($due['cycleMonths'])->toDateString();
                    $item->update([
                        'next_billing_date' => $advancedTo,
                        'next_invoice_date' => $advancedTo,
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
                    // The order line this period bills — the rewind and the
                    // paid-invoice advance resolve the item through it.
                    'order_item_id' => (int) $due['item']->id,
                    // The period this line bills, and the cycle the advance
                    // applies — what a void has to give back.
                    'billed_due_date' => CarbonImmutable::parse($due['dueDate'])->toDateString(),
                    'billed_cycle_months' => $due['cycleMonths'],
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

                // The invoice and the schedule advance that pays for it are one
                // unit of money-truth: commit them together, or a crash between
                // the two leaves an issued renewal invoice with its item still
                // due, and the next run bills the same period again.
                // createWithItems opens its own DB::transaction, which Laravel
                // nests as a savepoint (supported by both the MySQL production
                // driver and the SQLite test driver), so an outer rollback still
                // discards the whole invoice.
                $renewalInvoice = DB::transaction(function () use ($order, $invoiceAmount, $earliestDueDate, $items, $customerStateCode, $dueItems, $today): Invoice {
                    $invoice = $this->createWithItems([
                        'customer_id' => $order->customer_id,
                        'order_id' => $order->id,
                        'amount' => $invoiceAmount,
                        'status' => Invoice::STATUS_SENT,
                        'due_date' => $earliestDueDate->toDateString(),
                        'notes' => 'Auto-generated renewal invoice',
                    ], $items, $customerStateCode);

                    // Advance each billed item on ITS cycle FROM ITS OWN due
                    // date (not the run date) so an early-generation window
                    // never drifts the billing anniversary; the order summary
                    // date becomes the earliest remaining item date.
                    foreach ($dueItems as $due) {
                        $item = $due['item'];
                        $item->update([
                            'next_invoice_date' => CarbonImmutable::parse($due['dueDate'])->addMonths($due['cycleMonths'])->toDateString(),
                            'last_billing_date' => $today->toDateString(),
                            'billing_cycles_count' => $item->billing_cycles_count !== null
                                ? (int) $item->billing_cycles_count + 1
                                : null,
                        ]);
                    }

                    $this->syncOrderSummary($order, $today->toDateString());

                    return $invoice;
                });

                // The invoice and its schedule advance are committed, so the
                // invoice was generated: count it now, before the post-commit
                // steps, so nothing that fails there can hide it.
                $invoicesGenerated++;

                // WHMCS rule: an upgrade still unpaid when the renewal cron
                // bills the service is superseded by that renewal invoice —
                // cancel it now that the invoice is committed. This is internal
                // bookkeeping, not a client deliverable: a failure is logged at
                // warning and never counted as a billing error.
                try {
                    app(UpgradeRequestService::class)->cancelUnpaidForOrder($order);
                } catch (\Throwable $e) {
                    AppLog::billing()->warning('Renewal invoice issued but the superseded unpaid upgrade request was not cancelled', [
                        'order_id' => $order->id,
                        'invoice_no' => $renewalInvoice->invoice_no,
                        'error' => $e->getMessage(),
                    ]);
                }

                // The email IS the deliverable: a renewal invoice the client
                // never receives failed, even though the money row is correct.
                // Count it in errors so the run reports FAILURE; the invoice,
                // its status and the advanced schedule stay exactly as committed.
                $emailDispatched = false;
                $emailFailure = null;

                try {
                    $emailDispatched = app(InvoiceEmailService::class)->send($renewalInvoice);
                } catch (\Throwable $e) {
                    $emailFailure = $e->getMessage();
                }

                if (! $emailDispatched) {
                    $errors++;
                    AppLog::billing()->error('Renewal invoice issued but the invoice email was not dispatched; the client did not receive it', [
                        'order_id' => $order->id,
                        'invoice_no' => $renewalInvoice->invoice_no,
                        'error' => $emailFailure,
                    ]);
                }
            } catch (\Throwable $e) {
                $errors++;
                AppLog::billing()->error('Recurring billing failed for one order; run continues.', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return ['invoices_generated' => $invoicesGenerated, 'errors' => $errors];
    }

    /**
     * Advance each billed order line to the period its paid invoice covers.
     *
     * Generation moves only `next_invoice_date`; `next_billing_date` is the
     * pay-by date and must not move until the money arrives. This is where it
     * moves: for every line of a fully settled invoice the item's pay-by date
     * becomes max(current, billed_due_date + cycle), so a webhook retry or a
     * double settle cannot advance the schedule twice. When the item's
     * next_invoice_date is null the schedule has ended (cycle limit, fixed
     * term, cancellation), and its pay-by date is cleared with it.
     *
     * Lines resolve to items by order_item_id; only legacy rows without one
     * fall back to the product_id + date heuristic. Idempotent.
     *
     * @return array<int, array{order_item_id:int, advanced_to:string}> The order lines advanced.
     */
    public function advanceBillingSchedule(Invoice $invoice): array
    {
        if ($invoice->order_id === null || ! $invoice->isFullyPaid()) {
            return [];
        }

        if (in_array($invoice->status, [Invoice::STATUS_VOID, Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED], true)) {
            return [];
        }

        $order = Order::find($invoice->order_id);

        if ($order === null) {
            return [];
        }

        $advanced = [];

        DB::transaction(function () use ($invoice, $order, &$advanced) {
            foreach ($invoice->items()->get() as $line) {
                if ($line->billed_due_date === null || $line->billed_cycle_months === null) {
                    continue;
                }

                $billedDue = $line->billed_due_date->toDateString();
                $advancedTo = CarbonImmutable::parse($billedDue)->addMonths((int) $line->billed_cycle_months)->toDateString();

                $item = $this->resolveBilledOrderItem($order, $line, [$billedDue, $advancedTo]);

                if ($item === null) {
                    continue;
                }

                $currentDueDate = $item->next_billing_date?->toDateString();

                // A null generation pointer means the schedule ended; the
                // pay-by date ends with it rather than chasing a dead clock.
                $newDueDate = $item->next_invoice_date === null
                    ? null
                    : ($currentDueDate === null ? $advancedTo : max($currentDueDate, $advancedTo));

                if ($newDueDate === $currentDueDate) {
                    continue;
                }

                $item->update(['next_billing_date' => $newDueDate]);

                $advanced[] = ['order_item_id' => (int) $item->id, 'advanced_to' => $advancedTo];
            }

            $this->syncOrderSummary($order);
        });

        return $advanced;
    }

    /**
     * Give a billing period back when the invoice that billed it dies.
     *
     * Generation advanced the item's next_invoice_date and, once the invoice
     * was paid, advanceBillingSchedule moved its next_billing_date. A voided or
     * cancelled invoice must hand the period back on both pointers — otherwise
     * the service silently stops billing for it until the next cycle.
     *
     * next_invoice_date returns to the billed period; next_billing_date goes
     * back to it only if it had already been advanced past it (min()), so
     * voiding an unpaid renewal only re-arms the generation pointer. A line
     * that has since been billed again is left alone.
     *
     * @return array<int, array{order_item_id:int, restored_to:string}> The order lines rewound.
     */
    public function rewindBillingSchedule(Invoice $invoice): array
    {
        if ($invoice->order_id === null) {
            return [];
        }

        $order = Order::find($invoice->order_id);

        if ($order === null) {
            return [];
        }

        $restored = [];

        DB::transaction(function () use ($invoice, $order, &$restored) {
            foreach ($invoice->items()->get() as $line) {
                if ($line->billed_due_date === null || $line->billed_cycle_months === null) {
                    continue;
                }

                $billedDue = $line->billed_due_date->toDateString();
                $advancedTo = CarbonImmutable::parse($billedDue)->addMonths((int) $line->billed_cycle_months)->toDateString();

                $item = $this->resolveBilledOrderItem($order, $line, [$advancedTo, $billedDue]);

                if ($item === null) {
                    continue;
                }

                // The period must still be the item's current one: a service
                // billed again since then keeps the newer schedule.
                $currentInvoiceDate = $item->next_invoice_date?->toDateString();

                if ($currentInvoiceDate !== null && $currentInvoiceDate !== $advancedTo) {
                    continue;
                }

                $currentDueDate = $item->next_billing_date?->toDateString();

                $item->update([
                    'next_invoice_date' => $billedDue,
                    'next_billing_date' => $currentDueDate === null ? $billedDue : min($currentDueDate, $billedDue),
                    'last_billing_date' => null,
                    'billing_cycles_count' => $item->billing_cycles_count !== null
                        ? max(0, (int) $item->billing_cycles_count - 1)
                        : null,
                ]);

                $restored[] = ['order_item_id' => (int) $item->id, 'restored_to' => $billedDue];
            }

            $this->syncOrderSummary($order);
        });

        return $restored;
    }

    /**
     * The order item an invoice line bills: by order_item_id when recorded,
     * else the historical product_id + next_billing_date heuristic for lines
     * created before the column existed.
     *
     * @param  array<int, string>  $acceptableDates  next_billing_date values the
     *                                               legacy match may carry.
     */
    private function resolveBilledOrderItem(Order $order, InvoiceItem $line, array $acceptableDates): ?OrderItem
    {
        if ($line->order_item_id !== null) {
            $item = $order->items()->whereKey($line->order_item_id)->first();

            if ($item !== null) {
                return $item;
            }
        }

        if ($line->product_id === null) {
            return null;
        }

        return $order->items()
            ->where('product_id', $line->product_id)
            ->get()
            ->first(fn (OrderItem $candidate) => in_array($candidate->next_billing_date?->toDateString(), $acceptableDates, true));
    }

    /**
     * Keep the order-level billing summary in sync with its items: the order's
     * next_billing_date is the earliest item pay-by date and its
     * next_invoice_date the earliest item generation date (null when no item
     * is still recurring), and last_billing_date records the most recent
     * billing.
     *
     * An order WITHOUT items is the exception: it carries its own schedule
     * (activation seeds it for legacy order-level orders — OrderService
     * ::transition), and there is no item to derive a summary from. Writing
     * null over it would wipe a schedule that is still live, so its
     * next_billing_date / next_invoice_date are left untouched; only
     * last_billing_date is applied.
     */
    public function syncOrderSummary(Order $order, ?string $lastBillingDate = null): void
    {
        $items = $order->items()->get();

        $attributes = [];

        if ($items->isNotEmpty()) {
            $nextBillingDates = $items->whereNotNull('next_billing_date')->pluck('next_billing_date');
            $nextInvoiceDates = $items->whereNotNull('next_invoice_date')->pluck('next_invoice_date');

            $attributes['next_billing_date'] = $nextBillingDates->isNotEmpty()
                ? $nextBillingDates->min()->format('Y-m-d')
                : null;
            $attributes['next_invoice_date'] = $nextInvoiceDates->isNotEmpty()
                ? $nextInvoiceDates->min()->format('Y-m-d')
                : null;
        }

        if ($lastBillingDate !== null) {
            $attributes['last_billing_date'] = $lastBillingDate;
        }

        if ($attributes === []) {
            return;
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
     * When an item's term elapses, THAT item's recurring billing ends (both
     * pointer columns are cleared — the same end-state the recurring-cycles
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
                        $item->update(['next_billing_date' => null, 'next_invoice_date' => null]);
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
            } catch (\Throwable $e) {
                $errors++;
                AppLog::billing()->error('Recurring billing failed for one order; run continues.', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return ['terminated' => $terminated, 'errors' => $errors];
    }

    /**
     * Refund a paid or partially paid invoice: credit the paid amount back to
     * the customer's wallet and mark the invoice refunded. Amounts are kept
     * for audit — nothing is voided or deleted.
     *
     * @throws InvalidArgumentException
     */
    public function refund(Invoice $invoice, ?string $reason = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            if (! in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_PARTIAL], true)) {
                throw new InvalidArgumentException('Only paid or partially paid invoices can be refunded.');
            }

            $paidAmount = (float) ($invoice->paid_amount ?? 0);

            if ($paidAmount <= 0) {
                $paidAmount = (float) Payment::where('invoice_id', $invoice->id)
                    ->where('status', 'completed')
                    ->sum('amount');
            }

            if ($invoice->customer_id !== null && (int) $invoice->customer_id > 0 && $paidAmount > 0) {
                Customer::where('id', $invoice->customer_id)->increment('credit', $paidAmount);

                CustomerWallet::create([
                    'customer_id' => $invoice->customer_id,
                    'type' => 'refund',
                    'amount' => $paidAmount,
                    'balance_type' => 'credit',
                    'description' => 'Refund for invoice '.$invoice->invoice_no.($reason !== null && $reason !== '' ? ': '.$reason : ''),
                    'invoice_id' => $invoice->id,
                ]);
            }

            $invoice->update([
                'status' => Invoice::STATUS_REFUNDED,
                'notes' => trim((string) $invoice->notes.($reason !== null && $reason !== '' ? "\nRefunded: ".$reason : "\nRefunded.")),
            ]);

            return $invoice->fresh();
        });
    }
}
