<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;

class ManualDriver implements PaymentGatewayDriver
{
    public const STATUS_MANUAL = 'manual';

    public function isOnline(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function purchase(array $payload): array
    {
        return [
            'status' => self::STATUS_MANUAL,
            'reference' => $payload['purchase_ref'],
            'redirect_url' => null,
            'instructions' => null,
            'message' => 'Payment recorded manually by an administrator.',
        ];
    }

    public function verify(array $payload): array
    {
        return [
            'verified' => false,
            'gateway_transaction_id' => null,
            'amount' => null,
            'message' => null,
        ];
    }
}
