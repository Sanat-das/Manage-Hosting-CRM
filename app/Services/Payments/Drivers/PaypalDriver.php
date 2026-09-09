<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;
use App\Contracts\PaymentWebhookDriver;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PaypalDriver implements PaymentGatewayDriver, PaymentWebhookDriver
{
    public const SANDBOX_URL = 'https://api-m.sandbox.paypal.com';

    public const LIVE_URL = 'https://api-m.paypal.com';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_REDIRECT = 'redirect';

    /** Events that mean an order of ours may now be paid. */
    public const WEBHOOK_EVENTS = [
        'CHECKOUT.ORDER.APPROVED',
        'CHECKOUT.ORDER.COMPLETED',
        'PAYMENT.CAPTURE.COMPLETED',
    ];

    /** Headers PayPal's signature-verification API needs echoed back to it. */
    private const SIGNATURE_HEADERS = [
        'paypal-auth-algo' => 'auth_algo',
        'paypal-cert-url' => 'cert_url',
        'paypal-transmission-id' => 'transmission_id',
        'paypal-transmission-sig' => 'transmission_sig',
        'paypal-transmission-time' => 'transmission_time',
    ];

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

        $response = Http::withToken($this->accessToken($gateway))
            ->asJson()
            ->post($this->baseUrl($gateway).'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => $payload['purchase_ref'],
                        'description' => $payload['description'],
                        'amount' => [
                            'currency_code' => $payload['currency'],
                            'value' => $this->toCurrencyString($payload['amount']),
                        ],
                    ],
                ],
            ]);

        $response->throw();

        $data = $response->json() ?? [];

        return [
            'status' => self::STATUS_REDIRECT,
            'reference' => (string) ($data['id'] ?? ''),
            'redirect_url' => $this->approvalLink($data),
            'instructions' => null,
            'message' => null,
        ];
    }

    public function verify(array $payload): array
    {
        $gateway = $payload['gateway'];

        $this->assertConfigured($gateway);

        $response = Http::withToken($this->accessToken($gateway))
            ->get($this->baseUrl($gateway).'/v2/checkout/orders/'.$payload['reference']);

        $response->throw();

        $data = $response->json() ?? [];

        $verified = ($data['status'] ?? '') === self::STATUS_COMPLETED;

        return [
            'verified' => $verified,
            'gateway_transaction_id' => $verified ? (string) ($data['id'] ?? $payload['reference']) : null,
            'amount' => $this->captureAmount($data),
            'message' => $verified ? null : 'Payment not confirmed by PayPal.',
        ];
    }

    /**
     * PayPal does not use a shared HMAC secret: a delivery is authenticated by
     * asking PayPal to verify its own signature, which needs the webhook id
     * from the dashboard.
     *
     * When webhook_id is not configured we cannot authenticate the delivery, so
     * we refuse it. Note that even an authenticated delivery is not treated as
     * proof of payment — the caller re-reads the order through the orders API
     * before recording anything.
     */
    public function parseWebhook(array $request): array
    {
        $gateway = $request['gateway'];
        $webhookId = (string) $gateway->getCredential('webhook_id', '');

        if ($webhookId === '' || ! $this->isConfiguredFor($gateway)) {
            return $this->webhook(false, null, null, 'PayPal webhook_id is not configured.');
        }

        if (! $this->signatureVerified($gateway, $webhookId, $request)) {
            return $this->webhook(false, null, null, 'PayPal webhook signature could not be verified.');
        }

        $payload = $request['payload'];
        $event = isset($payload['event_type']) ? (string) $payload['event_type'] : null;

        if ($event === null || ! in_array($event, self::WEBHOOK_EVENTS, true)) {
            return $this->webhook(true, null, $event, 'Event not actionable.');
        }

        // We create checkout orders, so the order id is our reference: the
        // resource itself on CHECKOUT.*, and a related id on a capture.
        $resource = $payload['resource'] ?? [];
        $reference = str_starts_with($event, 'CHECKOUT.')
            ? ($resource['id'] ?? null)
            : ($resource['supplementary_data']['related_ids']['order_id'] ?? null);

        return $this->webhook(true, $reference !== null ? (string) $reference : null, $event, null);
    }

    /**
     * @param  array{payload: array<string, mixed>, raw: string, headers: array<string, string>, gateway: PaymentGateway}  $request
     */
    private function signatureVerified(PaymentGateway $gateway, string $webhookId, array $request): bool
    {
        $body = ['webhook_id' => $webhookId, 'webhook_event' => $request['payload']];

        foreach (self::SIGNATURE_HEADERS as $header => $field) {
            $value = $request['headers'][$header] ?? '';

            if ($value === '') {
                return false;
            }

            $body[$field] = $value;
        }

        try {
            $response = Http::withToken($this->accessToken($gateway))
                ->asJson()
                ->post($this->baseUrl($gateway).'/v1/notifications/verify-webhook-signature', $body);

            $response->throw();

            return ($response->json('verification_status') ?? '') === 'SUCCESS';
        } catch (Throwable) {
            // Unreachable PayPal means we cannot authenticate the delivery.
            // Refusing it is the safe answer: the payment stays pending and
            // either a later delivery or the customer's return settles it.
            return false;
        }
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
            throw new RuntimeException('PayPal is not configured. Set the client_id and client_secret credentials.');
        }
    }

    private function isConfiguredFor(PaymentGateway $gateway): bool
    {
        return $gateway->getCredential('client_id', '') !== ''
            && $gateway->getCredential('client_secret', '') !== '';
    }

    private function baseUrl(PaymentGateway $gateway): string
    {
        return $gateway->mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
    }

    private function accessToken(PaymentGateway $gateway): string
    {
        $response = Http::asForm()
            ->withBasicAuth($gateway->getCredential('client_id', ''), $gateway->getCredential('client_secret', ''))
            ->post($this->baseUrl($gateway).'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        $response->throw();

        return (string) ($response->json('access_token') ?? '');
    }

    private function approvalLink(array $data): ?string
    {
        foreach (($data['links'] ?? []) as $link) {
            if (($link['rel'] ?? '') === 'approve') {
                return isset($link['href']) ? (string) $link['href'] : null;
            }
        }

        return null;
    }

    private function captureAmount(array $data): ?float
    {
        $captures = $data['purchase_units'][0]['payments']['captures'] ?? [];

        return isset($captures[0]['amount']['value'])
            ? (float) $captures[0]['amount']['value']
            : null;
    }

    private function toCurrencyString(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
