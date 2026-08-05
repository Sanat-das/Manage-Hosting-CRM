<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StripeDriver implements PaymentGatewayDriver
{
    public const BASE_URL = 'https://api.stripe.com/v1';

    public const CHECKOUT_URL = 'https://checkout.stripe.com/c/pay';

    public const STATUS_SUCCEEDED = 'succeeded';

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
