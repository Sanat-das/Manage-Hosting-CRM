<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function returned(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->resolveCustomer($request, $invoice);

        $payment = Payment::where('invoice_id', $invoice->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($payment === null) {
            return redirect()->route('client.invoices.show', $invoice)
                ->with('warning', 'No pending payment was found for this invoice.');
        }

        $gateway = $this->resolveGatewayForPayment($payment);

        if ($gateway === null) {
            return redirect()->route('client.invoices.show', $invoice)
                ->with('error', 'The payment gateway for this payment could not be resolved.');
        }

        try {
            $result = app(PaymentGatewayManager::class)
                ->driverFor($gateway)
                ->verify(['reference' => $payment->transaction_id, 'gateway' => $gateway]);
        } catch (RuntimeException $e) {
            return redirect()->route('client.invoices.show', $invoice)
                ->with('error', $e->getMessage());
        }

        if (! ($result['verified'] ?? false)) {
            return redirect()->route('client.invoices.show', $invoice)
                ->with('error', $result['message'] ?? 'Payment could not be confirmed. Please contact support.');
        }

        DB::transaction(function () use ($payment, $invoice, $result) {
            $payment->update([
                'status' => 'completed',
                'transaction_id' => $result['gateway_transaction_id'] ?? $payment->transaction_id,
            ]);

            $paid = (float) Payment::where('invoice_id', $invoice->id)
                ->where('status', 'completed')
                ->sum('amount');

            $fullyPaid = $paid >= (float) $invoice->total;

            $invoice->update([
                'paid_amount' => $paid,
                'status' => $fullyPaid ? 'paid' : 'partial',
                'paid_at' => $fullyPaid ? now() : $invoice->paid_at,
            ]);
        });

        return redirect()->route('client.invoices.show', $invoice)
            ->with('success', 'Payment received. Thank you!');
    }

    public function pending(Request $request, Payment $payment): View
    {
        $invoice = $payment->invoice;

        $this->resolveCustomer($request, $invoice);

        return view('client.payments.pending', compact('payment', 'invoice'));
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
