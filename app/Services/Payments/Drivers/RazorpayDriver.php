<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RazorpayDriver implements PaymentGatewayDriver
{
    public const BASE_URL = 'https://api.razorpay.com/v1';

    public const STATUS_PAID = 'paid';

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
