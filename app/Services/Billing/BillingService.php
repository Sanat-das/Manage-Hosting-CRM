<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\GstSetting;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Payment;
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
     * @param  array  $invoiceData       customer_id, amount, status, due_date, notes, (order_id, discount).
     * @param  array  $items             Each item: description, quantity, unit_price, total, (product_id).
     * @param  string|null  $customerStateCode  Customer's 2-letter state code.
     * @return Invoice
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
     * Mark an invoice paid by recording a completed payment.
     *
     * Port of InvoiceModel::markPaid L377-400: creates the payment, then sets
     * status 'paid' (with paid_at) when the completed-payment sum reaches the
     * total, otherwise 'partial'.
     *
     * @return int  Payment id.
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
     * Port of Automation::processRecurringBilling L26-87: orders with
     * status 'active' whose next_billing_date is null or due; one 'sent'
     * invoice per order with due_date = today + 7; then next_billing_date
     * advances by the cycle months.
     *
     * Cycle → months: monthly=1, quarterly=3, semi_annual=6, annual=12,
     * biennial=24 (default 1).
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
            ->where(function ($query) use ($today) {
                $query->whereNull('next_billing_date')
                    ->orWhere('next_billing_date', '<=', $today->toDateString());
            })
            ->with(['items', 'product'])
            ->get();

        $invoicesGenerated = 0;
        $errors = 0;

        foreach ($orders as $order) {
            try {
                $orderItem = $order->items->first();
                if (! $orderItem) {
                    $errors++;
                    continue;
                }

                $cycle = (string) ($order->product?->billing_cycle ?? 'monthly');
                $cycleMonths = match ($cycle) {
                    'monthly' => 1,
                    'quarterly' => 3,
                    'semi_annual' => 6,
                    'annual' => 12,
                    'biennial' => 24,
                    default => 1,
                };

                $items = [[
                    'description' => $orderItem->product_name.' - '.ucfirst(str_replace('_', ' ', $cycle)),
                    'quantity' => 1,
                    'unit_price' => (float) $orderItem->unit_price,
                    'total' => (float) $orderItem->unit_price,
                ]];

                // RENEWAL-IGST FIX (decisions.md #6): the reference passed a null
                // customer state → every renewal was inter-state → IGST. Resolve
                // the customer's state first and fall back to the company state
                // (intra-state) when it is unknown, so renewals never default to
                // the higher IGST rate.
                $customerStateCode = $this->resolveCustomerStateCode((int) $order->customer_id) ?? $companyStateCode;

                $this->createWithItems([
                    'customer_id' => $order->customer_id,
                    'order_id' => $order->id,
                    'amount' => (float) $orderItem->unit_price,
                    'status' => 'sent',
                    'due_date' => $today->addDays(7)->toDateString(),
                    'notes' => 'Auto-generated renewal invoice',
                ], $items, $customerStateCode);

                $order->update([
                    'next_billing_date' => $today->addMonths($cycleMonths)->toDateString(),
                    'last_billing_date' => $today->toDateString(),
                ]);

                $invoicesGenerated++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        return ['invoices_generated' => $invoicesGenerated, 'errors' => $errors];
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
}
