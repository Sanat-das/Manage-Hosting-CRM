<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;
use App\Models\PaymentGateway;

class BankTransferDriver implements PaymentGatewayDriver
{
    public const STATUS_MANUAL = 'manual';

    public function __construct(private readonly ?PaymentGateway $gateway = null) {}

    public function isOnline(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return $this->gateway !== null && $this->isConfiguredFor($this->gateway);
    }

    public function purchase(array $payload): array
    {
        return [
            'status' => self::STATUS_MANUAL,
            'reference' => $payload['purchase_ref'],
            'redirect_url' => null,
            'instructions' => $this->instructions($payload['gateway']),
            'message' => 'Complete the bank transfer using the instructions below.',
        ];
    }

    public function verify(array $payload): array
    {
        return [
            'verified' => false,
            'gateway_transaction_id' => null,
            'amount' => null,
            'message' => 'Awaiting manual confirmation.',
        ];
    }

    private function isConfiguredFor(PaymentGateway $gateway): bool
    {
        return $gateway->getCredential('account_number', '') !== '';
    }

    private function instructions(PaymentGateway $gateway): array
    {
        return array_filter([
            'Account Name' => $gateway->getCredential('account_name'),
            'Account Number' => $gateway->getCredential('account_number'),
            'Bank Name' => $gateway->getCredential('bank_name'),
            'IFSC Code' => $gateway->getCredential('ifsc'),
            'Instructions' => $gateway->getCredential('instructions'),
        ], fn ($value) => $value !== null && $value !== '');
    }
}
