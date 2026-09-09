<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;
use App\Contracts\PaymentWebhookDriver;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RazorpayDriver implements PaymentGatewayDriver, PaymentWebhookDriver
{
    public const BASE_URL = 'https://api.razorpay.com/v1';

    public const STATUS_PAID = 'paid';

    public const STATUS_REDIRECT = 'redirect';

    /** Razorpay's HMAC header over the raw request body. */
    public const SIGNATURE_HEADER = 'x-razorpay-signature';

    /** Events that mean an order of ours may now be paid. */
    public const WEBHOOK_EVENTS = ['order.paid', 'payment.captured', 'payment.authorized'];

    public function __construct(private readonly ?PaymentGateway $gateway = null) {}

    public function isOnline(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return $this->gateway !== null && $this->isConfiguredFor($this->gateway);
    }

    public function purchase(array $payload): array
    {
        $gateway = $payload['gateway'];

        $this->assertConfigured($gateway);

        $response = Http::withBasicAuth($gateway->getCredential('key_id', ''), $gateway->getCredential('key_secret', ''))
            ->asForm()
            ->post(self::BASE_URL.'/orders', [
                'amount' => $this->toPaise($payload['amount']),
                'currency' => $payload['currency'],
                'receipt' => $payload['purchase_ref'],
                'notes' => ['description' => $payload['description']],
            ]);

        $response->throw();

        $data = $response->json() ?? [];

        return [
            'status' => self::STATUS_REDIRECT,
            'reference' => (string) ($data['id'] ?? ''),
            'redirect_url' => isset($data['short_url']) ? (string) $data['short_url'] : null,
            'instructions' => null,
            'message' => null,
        ];
    }

    public function verify(array $payload): array
    {
        $gateway = $payload['gateway'];

        $this->assertConfigured($gateway);

        $response = Http::withBasicAuth($gateway->getCredential('key_id', ''), $gateway->getCredential('key_secret', ''))
            ->get(self::BASE_URL.'/orders/'.$payload['reference']);

        $response->throw();

        $data = $response->json() ?? [];

        $verified = ($data['status'] ?? '') === self::STATUS_PAID;

        return [
            'verified' => $verified,
            'gateway_transaction_id' => $verified ? (string) ($data['id'] ?? $payload['reference']) : null,
            'amount' => isset($data['amount']) ? (float) $data['amount'] / 100 : null,
            'message' => $verified ? null : 'Payment not confirmed by Razorpay.',
        ];
    }

    /**
     * Razorpay signs the raw body with the webhook secret (HMAC-SHA256, hex)
     * and sends it in X-Razorpay-Signature.
     *
     * The secret is a separate credential from key_secret — it is set when the
     * webhook is created in the Razorpay dashboard. Without it configured we
     * refuse the delivery rather than trusting an unsigned POST.
     */
    public function parseWebhook(array $request): array
    {
        $gateway = $request['gateway'];
        $secret = (string) $gateway->getCredential('webhook_secret', '');

        if ($secret === '') {
            return $this->webhook(false, null, null, 'Razorpay webhook_secret is not configured.');
        }

        $signature = (string) ($request['headers'][self::SIGNATURE_HEADER] ?? '');
        $expected = hash_hmac('sha256', $request['raw'], $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return $this->webhook(false, null, null, 'Razorpay webhook signature mismatch.');
        }

        $payload = $request['payload'];
        $event = isset($payload['event']) ? (string) $payload['event'] : null;

        if ($event === null || ! in_array($event, self::WEBHOOK_EVENTS, true)) {
            return $this->webhook(true, null, $event, 'Event not actionable.');
        }

        // We create Razorpay *orders*, so the order id is the reference our
        // pending payment row stores. A payment.* event carries it on the
        // payment entity instead.
        $reference = $payload['payload']['order']['entity']['id']
            ?? $payload['payload']['payment']['entity']['order_id']
            ?? null;

        return $this->webhook(true, $reference !== null ? (string) $reference : null, $event, null);
    }

    /**
     * @return array{valid: bool, reference: ?string, event: ?string, message: ?string}
     */
    private function webhook(bool $valid, ?string $reference, ?string $event, ?string $message): array
    {
        return ['valid' => $valid, 'reference' => $reference, 'event' => $event, 'message' => $message];
    }

    private function assertConfigured(PaymentGateway $gateway): void
    {
        if (! $this->isConfiguredFor($gateway)) {
            throw new RuntimeException('Razorpay is not configured. Set the key_id and key_secret credentials.');
        }
    }

    private function isConfiguredFor(PaymentGateway $gateway): bool
    {
        return $gateway->getCredential('key_id', '') !== ''
            && $gateway->getCredential('key_secret', '') !== '';
    }

    private function toPaise(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
