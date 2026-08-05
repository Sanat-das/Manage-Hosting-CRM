<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\PaymentGateway;

interface PaymentGatewayDriver
{
    /** Whether this gateway processes payments remotely (stripe/paypal/razorpay). */
    public function isOnline(): bool;

    /** Whether required credentials are present (false disables the gateway). */
    public function isConfigured(): bool;

    /**
     * Start a payment for a given amount.
     *
     * @param  array{purchase_ref: string, amount: float, currency: string, description: string, gateway: PaymentGateway}  $payload
     * @return array{status: string, reference: ?string, redirect_url: ?string, instructions: ?array, message: ?string}
     *                                                                                                                  status ∈ redirect|manual|pending. redirect → redirect_url required; manual → instructions array.
     */
    public function purchase(array $payload): array;

    /**
     * Verify a payment after the client returns from the gateway.
     *
     * @param  array{reference: string, gateway: PaymentGateway}  $payload
     * @return array{verified: bool, gateway_transaction_id: ?string, amount: ?float, message: ?string}
     */
    public function verify(array $payload): array;
}
