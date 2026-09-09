<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\PaymentGateway;

/**
 * Optional capability: gateways that can push us an asynchronous notification
 * when a payment completes.
 *
 * Without this, a redirect payment is only ever confirmed if the customer's
 * browser makes it back to the return URL — close the tab at the gateway and
 * the payment stays pending forever, the invoice unpaid, and the order
 * unprovisioned, with nothing in the system that would ever notice.
 *
 * The driver's job here is deliberately narrow: prove the delivery is genuine
 * and say which payment it is about. It does NOT report success — the webhook
 * body is not evidence of payment. PaymentSettlementService re-queries the
 * gateway's API for the verdict before a rupee is recorded.
 */
interface PaymentWebhookDriver
{
    /**
     * Authenticate a webhook delivery and extract the payment reference.
     *
     * `reference` must match what purchase() returned as its reference (the
     * gateway's order/intent id), which is what the pending payments row
     * stores in transaction_id.
     *
     * A delivery that is genuine but irrelevant (a gateway event we do not act
     * on) is `valid` with a null `reference` — that is a 202, not an error.
     *
     * @param  array{payload: array<string, mixed>, raw: string, headers: array<string, string>, gateway: PaymentGateway}  $request
     * @return array{valid: bool, reference: ?string, event: ?string, message: ?string}
     */
    public function parseWebhook(array $request): array;
}
