<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Services\Billing\BillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a pending online payment into money against an invoice.
 *
 * There are two ways a redirect payment comes back — the customer's browser
 * returning to the return URL, and the gateway's webhook — and they can both
 * arrive, in either order, for the same payment. This is the single place that
 * settles one, so the two paths cannot each credit the invoice, and so the
 * webhook gets exactly the accounting the return path gets: partial payments,
 * overpayment credited to the customer's wallet, and the InvoicePaid event that
 * advances the order (all of which live in BillingService::recordPayment, which
 * PaymentController::returned used to bypass with its own hand-rolled update).
 *
 * The gateway is ALWAYS re-queried before any money is recorded. Neither a
 * browser landing on a return URL nor a webhook body is evidence of payment;
 * only the gateway's own API answer is.
 */
final class PaymentSettlementService
{
    /** The payment was verified and recorded against the invoice. */
    public const SETTLED = 'settled';

    /** Another callback got there first — nothing to do. */
    public const ALREADY_SETTLED = 'already_settled';

    /** The gateway does not (yet) consider this payment complete. */
    public const NOT_VERIFIED = 'not_verified';

    /** The gateway could not be asked (misconfigured, unreachable). */
    public const UNAVAILABLE = 'unavailable';

    public function __construct(private readonly BillingService $billing) {}

    /**
     * @return array{status: string, message: ?string, result: ?array<string, mixed>}
     */
    public function settle(Payment $payment, PaymentGateway $gateway): array
    {
        if ($payment->status !== 'pending') {
            return $this->outcome(self::ALREADY_SETTLED, 'This payment has already been processed.');
        }

        try {
            $verification = app(PaymentGatewayManager::class)
                ->driverFor($gateway)
                ->verify(['reference' => (string) $payment->transaction_id, 'gateway' => $gateway]);
        } catch (Throwable $e) {
            Log::warning('Payment verification call failed', [
                'payment_id' => $payment->id,
                'gateway' => $gateway->code,
                'error' => $e->getMessage(),
            ]);

            return $this->outcome(self::UNAVAILABLE, $e->getMessage());
        }

        if (! ($verification['verified'] ?? false)) {
            return $this->outcome(
                self::NOT_VERIFIED,
                $verification['message'] ?? 'Payment could not be confirmed. Please contact support.',
            );
        }

        // The gateway's figure wins over the amount we hoped to collect: a
        // customer who paid less than the invoice must produce a partial
        // payment, not a settled invoice.
        $confirmed = (float) ($verification['amount'] ?? 0);
        $amount = $confirmed > 0 ? $confirmed : (float) $payment->amount;

        $transactionId = (string) ($verification['gateway_transaction_id'] ?? $payment->transaction_id);

        return DB::transaction(function () use ($payment, $gateway, $amount, $transactionId) {
            // Re-read under a lock: the webhook and the browser return can be
            // in flight at the same moment, and both have just been told by the
            // gateway that the money is there.
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'pending') {
                return $this->outcome(self::ALREADY_SETTLED, 'This payment has already been processed.');
            }

            // A payment can complete against an invoice that was voided while
            // the customer was at the gateway — an order cancelled mid-checkout
            // voids its outstanding invoices. Record it anyway: the money left
            // the customer's account, and dropping it on the floor would be
            // worse than recording something that needs refunding. Flagged
            // loudly because it needs a human.
            $invoice = $locked->invoice;

            if ($invoice !== null && in_array($invoice->status, ['void', 'cancelled'], true)) {
                Log::warning('Payment settled against a void/cancelled invoice — likely needs a refund', [
                    'payment_id' => $locked->id,
                    'invoice_id' => $invoice->id,
                    'invoice_status' => $invoice->status,
                    'amount' => $amount,
                ]);
            }

            $result = $this->billing->recordPayment(
                (int) $locked->invoice_id,
                $amount,
                $gateway->code,
                $transactionId,
                (int) $locked->id,
            );

            return $this->outcome(self::SETTLED, null, $result);
        });
    }

    /**
     * @param  array<string, mixed>|null  $result
     * @return array{status: string, message: ?string, result: ?array<string, mixed>}
     */
    private function outcome(string $status, ?string $message, ?array $result = null): array
    {
        return ['status' => $status, 'message' => $message, 'result' => $result];
    }
}
