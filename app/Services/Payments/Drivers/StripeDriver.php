<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;
use App\Contracts\PaymentWebhookDriver;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StripeDriver implements PaymentGatewayDriver, PaymentWebhookDriver
{
    public const BASE_URL = 'https://api.stripe.com/v1';

    public const CHECKOUT_URL = 'https://checkout.stripe.com/c/pay';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_REDIRECT = 'redirect';

    /** Stripe's signed header: `t=<timestamp>,v1=<hmac>[,v1=…]`. */
    public const SIGNATURE_HEADER = 'stripe-signature';

    /** How far a delivery's timestamp may drift before we reject it (seconds). */
    public const SIGNATURE_TOLERANCE = 300;

    /** Events that mean a payment intent of ours may now be paid. */
    public const WEBHOOK_EVENTS = ['payment_intent.succeeded', 'charge.succeeded'];

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

        $response = Http::withToken($gateway->getCredential('secret_key', ''))
            ->asForm()
            ->post(self::BASE_URL.'/payment_intents', [
                'amount' => $this->toSmallestUnit($payload['amount']),
                'currency' => strtolower($payload['currency']),
                'description' => $payload['description'],
            ]);

        $response->throw();

        $data = $response->json() ?? [];

        return [
            'status' => self::STATUS_REDIRECT,
            'reference' => (string) ($data['id'] ?? ''),
            'redirect_url' => self::CHECKOUT_URL.'/'.($data['client_secret'] ?? ''),
            'instructions' => null,
            'message' => null,
        ];
    }

    public function verify(array $payload): array
    {
        $gateway = $payload['gateway'];

        $this->assertConfigured($gateway);

        $response = Http::withToken($gateway->getCredential('secret_key', ''))
            ->get(self::BASE_URL.'/payment_intents/'.$payload['reference']);

        $response->throw();

        $data = $response->json() ?? [];

        $verified = ($data['status'] ?? '') === self::STATUS_SUCCEEDED;

        return [
            'verified' => $verified,
            'gateway_transaction_id' => $verified ? (string) ($data['id'] ?? $payload['reference']) : null,
            'amount' => isset($data['amount']) ? (float) $data['amount'] / 100 : null,
            'message' => $verified ? null : 'Payment not confirmed by Stripe.',
        ];
    }

    /**
     * Stripe signs `"<timestamp>.<raw body>"` with the endpoint's signing
     * secret (whsec_…) and sends timestamp + HMACs in one header.
     *
     * The timestamp is part of the signed payload and is checked against a
     * tolerance window, which is what stops a captured delivery being replayed
     * later.
     */
    public function parseWebhook(array $request): array
    {
        $gateway = $request['gateway'];
        $secret = (string) $gateway->getCredential('webhook_secret', '');

        if ($secret === '') {
            return $this->webhook(false, null, null, 'Stripe webhook_secret is not configured.');
        }

        $header = (string) ($request['headers'][self::SIGNATURE_HEADER] ?? '');
        [$timestamp, $signatures] = $this->parseSignatureHeader($header);

        if ($timestamp === null || $signatures === []) {
            return $this->webhook(false, null, null, 'Stripe signature header is missing or malformed.');
        }

        if (abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE) {
            return $this->webhook(false, null, null, 'Stripe webhook timestamp is outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request['raw'], $secret);
        $matched = false;

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            return $this->webhook(false, null, null, 'Stripe webhook signature mismatch.');
        }

        $payload = $request['payload'];
        $event = isset($payload['type']) ? (string) $payload['type'] : null;

        if ($event === null || ! in_array($event, self::WEBHOOK_EVENTS, true)) {
            return $this->webhook(true, null, $event, 'Event not actionable.');
        }

        // We create payment intents, so the intent id is our reference: it is
        // the object itself on payment_intent.*, and a property on a charge.
        $object = $payload['data']['object'] ?? [];
        $reference = ($object['object'] ?? '') === 'payment_intent'
            ? ($object['id'] ?? null)
            : ($object['payment_intent'] ?? null);

        return $this->webhook(true, $reference !== null ? (string) $reference : null, $event, null);
    }

    /**
     * @return array{0: ?int, 1: list<string>} [timestamp, v1 signatures]
     */
    private function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
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
            throw new RuntimeException('Stripe is not configured. Set the secret_key credential.');
        }
    }

    private function isConfiguredFor(PaymentGateway $gateway): bool
    {
        return $gateway->getCredential('secret_key', '') !== '';
    }

    private function toSmallestUnit(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
