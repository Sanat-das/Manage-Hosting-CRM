<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Contracts\PaymentWebhookDriver;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gateway-to-server payment notifications.
 *
 * The reason this exists: a redirect payment used to be confirmed only when the
 * customer's browser came back to the return URL. Anyone who closed the tab at
 * the gateway, lost their connection, or was bounced by a mobile banking app
 * left a `pending` payment behind — the invoice unpaid and the order never
 * provisioned, with no job or reconciliation pass that would ever pick it up.
 *
 * Deliberate properties:
 *  - unauthenticated by design, so authenticity comes from the driver's
 *    signature check (parseWebhook), not from a session;
 *  - the body is never treated as proof of payment — PaymentSettlementService
 *    re-queries the gateway before recording money;
 *  - idempotent, because gateways retry: a delivery for an already-settled
 *    payment is a 200 with no side effects;
 *  - noisy failures answer 2xx where a retry would not help (unknown
 *    reference, event we do not act on) and non-2xx only where the gateway
 *    SHOULD retry, so we do not train a gateway to disable the endpoint.
 *
 * Route: POST /webhooks/payments/{gateway} (routes/webhooks.php)
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentSettlementService $settlement) {}

    public function __invoke(Request $request, string $gateway): JsonResponse
    {
        $model = PaymentGateway::query()
            ->where('code', $gateway)
            ->where('enabled', true)
            ->first();

        if ($model === null) {
            return $this->respond(404, 'unknown_gateway');
        }

        try {
            $driver = app(PaymentGatewayManager::class)->driverFor($model);
        } catch (Throwable $e) {
            Log::error('Payment webhook could not resolve a driver', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
            ]);

            return $this->respond(500, 'driver_unavailable');
        }

        if (! $driver instanceof PaymentWebhookDriver) {
            return $this->respond(404, 'gateway_has_no_webhook_support');
        }

        $parsed = $driver->parseWebhook([
            'payload' => (array) $request->json()->all(),
            'raw' => $request->getContent(),
            // Header names are normalised to lower case so drivers can look
            // them up by a fixed key regardless of how the sender cased them.
            'headers' => $this->headers($request),
            'gateway' => $model,
        ]);

        if (! ($parsed['valid'] ?? false)) {
            Log::warning('Rejected payment webhook', [
                'gateway' => $gateway,
                'reason' => $parsed['message'] ?? null,
                'ip' => $request->ip(),
            ]);

            // 400, not 401: this is a malformed/forged delivery, and a retry of
            // the same bytes will fail identically.
            return $this->respond(400, 'invalid_signature');
        }

        $reference = $parsed['reference'] ?? null;

        if ($reference === null || $reference === '') {
            // Genuine delivery for an event we do not act on.
            return $this->respond(202, 'ignored', ['event' => $parsed['event'] ?? null]);
        }

        $payment = Payment::where('transaction_id', $reference)
            ->where('method', $model->code)
            ->latest('id')
            ->first();

        if ($payment === null) {
            Log::info('Payment webhook referenced an unknown payment', [
                'gateway' => $gateway,
                'reference' => $reference,
            ]);

            // 202: the delivery is genuine but nothing here matches it (a
            // payment started elsewhere, or a test event from the dashboard).
            // Retrying would not change that.
            return $this->respond(202, 'unknown_reference');
        }

        if ($payment->status !== 'pending') {
            return $this->respond(200, 'already_processed', ['payment_id' => $payment->id]);
        }

        $outcome = $this->settlement->settle($payment, $model);

        return match ($outcome['status']) {
            PaymentSettlementService::SETTLED => $this->respond(200, 'settled', [
                'payment_id' => $payment->id,
                'invoice_status' => $outcome['result']['status'] ?? null,
            ]),
            PaymentSettlementService::ALREADY_SETTLED => $this->respond(200, 'already_processed', [
                'payment_id' => $payment->id,
            ]),
            // The gateway says it is not complete yet — a later delivery may
            // say otherwise, so accept this one without asking for a retry.
            PaymentSettlementService::NOT_VERIFIED => $this->respond(202, 'not_verified'),
            // We could not reach the gateway to confirm. This one SHOULD be
            // retried, so answer 503.
            default => $this->respond(503, 'verification_unavailable'),
        };
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function respond(int $status, string $result, array $extra = []): JsonResponse
    {
        return response()->json(['result' => $result] + $extra, $status);
    }
}
