<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaypalDriver implements PaymentGatewayDriver
{
    public const SANDBOX_URL = 'https://api-m.sandbox.paypal.com';

    public const LIVE_URL = 'https://api-m.paypal.com';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_REDIRECT = 'redirect';

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
