<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Client portal — online (redirect) and manual payment flow for invoices.
 */
class PaymentController extends Controller
{
    public function show(Request $request, Invoice $invoice): View|RedirectResponse
    {
        $this->resolveCustomer($request, $invoice);

        if ($invoice->isFullyPaid()) {
            return redirect()->route('client.invoices.show', $invoice)
                ->with('info', 'This invoice has already been paid.');
        }

        if ($guard = $this->uncollectable($invoice)) {
            return $guard;
        }

        $manager = app(PaymentGatewayManager::class);

        $gateways = $manager->enabled()->filter(
            fn (PaymentGateway $gateway) => $gateway->isConfigured() || ! $gateway->isOnline()
        );

        $dueAmount = $invoice->dueAmount();

        return view('client.payments.pay', compact('invoice', 'gateways', 'dueAmount'));
    }

    public function purchase(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->resolveCustomer($request, $invoice);

        if ($invoice->isFullyPaid()) {
            return redirect()->route('client.invoices.show', $invoice)
                ->with('info', 'This invoice has already been paid.');
        }

        if ($guard = $this->uncollectable($invoice)) {
            return $guard;
        }

        $manager = app(PaymentGatewayManager::class);

        $usableCodes = $manager->enabled()
            ->filter(fn (PaymentGateway $gateway) => $gateway->isConfigured() || ! $gateway->isOnline())
            ->pluck('code')
            ->all();

        $validated = $request->validate([
            'gateway' => ['required', 'string', Rule::in($usableCodes)],
        ]);

        $gateway = PaymentGateway::where('code', $validated['gateway'])->firstOrFail();

        $payload = [
            'purchase_ref' => (string) $invoice->id.'-'.Str::random(8),
            'amount' => (float) max(0.0, (float) $invoice->total - (float) $invoice->paid_amount),
            'currency' => 'INR',
            'description' => "Invoice {$invoice->invoice_no}",
            'gateway' => $gateway,
        ];

        try {
            $result = $manager->driverFor($gateway)->purchase($payload);
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $payload['amount'],
            'method' => $gateway->code,
            'gateway_id' => (string) $gateway->id,
            'transaction_id' => $result['reference'] ?? $payload['purchase_ref'],
            'status' => 'pending',
            'notes' => $result['message'] ?? null,
        ]);

        if ($result['status'] === 'redirect' && ($result['redirect_url'] ?? null) !== null) {
            return redirect()->away($result['redirect_url']);
        }

        session()->flash('payment_instructions', $result['instructions'] ?? []);
        session()->flash('payment_message', $result['message'] ?? null);

        return redirect()->route('client.payments.pending', $payment);
    }

    /**
     * The customer's browser coming back from the gateway.
     *
     * This is now one of TWO ways a payment is confirmed — the gateway's
     * webhook is the other, and either may arrive first (or alone). Both go
     * through PaymentSettlementService, so the invoice can never be credited
     * twice, and both get the full billing treatment: partial payments,
     * overpayment credited to the customer's wallet, and the InvoicePaid event.
     * This method used to hand-roll its own invoice update, which meant an
     * overpayment made online was simply kept.
     */
    public function returned(Request $request, Invoice $invoice, PaymentSettlementService $settlement): RedirectResponse
    {
        $this->resolveCustomer($request, $invoice);

        $payment = Payment::where('invoice_id', $invoice->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($payment === null) {
            // No pending payment can also mean the webhook beat the customer
            // back and already settled it — say so rather than warning them
            // that something is missing.
            if ($invoice->fresh()->isFullyPaid()) {
                return redirect()->route('client.invoices.show', $invoice)
                    ->with('success', 'Payment received. Thank you!');
            }

            return redirect()->route('client.invoices.show', $invoice)
                ->with('warning', 'No pending payment was found for this invoice.');
        }

        $gateway = $this->resolveGatewayForPayment($payment);

        if ($gateway === null) {
            return redirect()->route('client.invoices.show', $invoice)
                ->with('error', 'The payment gateway for this payment could not be resolved.');
        }

        $outcome = $settlement->settle($payment, $gateway);

        return match ($outcome['status']) {
            PaymentSettlementService::SETTLED,
            PaymentSettlementService::ALREADY_SETTLED => redirect()
                ->route('client.invoices.show', $invoice)
                ->with('success', 'Payment received. Thank you!'),
            default => redirect()
                ->route('client.invoices.show', $invoice)
                ->with('error', $outcome['message'] ?? 'Payment could not be confirmed. Please contact support.'),
        };
    }

    public function pending(Request $request, Payment $payment): View
    {
        $invoice = $payment->invoice;

        $this->resolveCustomer($request, $invoice);

        return view('client.payments.pending', compact('payment', 'invoice'));
    }

    /**
     * Stop a customer STARTING a payment against an invoice that is no longer
     * collectable, or null when it is fine to proceed.
     *
     * A void or cancelled invoice was still fully payable from here: the only
     * guard was isFullyPaid(). Since an ending order now voids its outstanding
     * invoices, that would have let a customer pay for an order that had been
     * cancelled out from under them. Mirrors the guard the admin invoice page
     * already applies in InvoiceController::storePayment().
     *
     * Note this deliberately only blocks STARTING a payment. A payment already
     * in flight when the invoice was voided is still settled when it comes
     * back — see PaymentSettlementService. Money that actually moved must be
     * recorded, not dropped.
     */
    private function uncollectable(Invoice $invoice): ?RedirectResponse
    {
        if (! in_array($invoice->status, [Invoice::STATUS_VOID, Invoice::STATUS_CANCELLED], true)) {
            return null;
        }

        return redirect()->route('client.invoices.show', $invoice)
            ->with('warning', 'This invoice has been cancelled and can no longer be paid. Please contact support if you believe this is a mistake.');
    }

    private function resolveCustomer(Request $request, Invoice $invoice): Customer
    {
        $customer = $request->user()?->customer;

        abort_unless($customer, 404);
        abort_unless($invoice->customer_id === $customer->id, 403);

        return $customer;
    }

    private function resolveGatewayForPayment(Payment $payment): ?PaymentGateway
    {
        if ($payment->gateway_id !== null) {
            $gateway = PaymentGateway::find($payment->gateway_id);

            if ($gateway !== null) {
                return $gateway;
            }
        }

        return PaymentGateway::where('code', $payment->method)->first();
    }
}
