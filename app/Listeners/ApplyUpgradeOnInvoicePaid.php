<?php

namespace App\Listeners;

use App\Events\InvoicePaid;
use App\Models\UpgradeRequest;
use App\Services\Billing\UpgradeRequestService;
use App\Support\Logging\AppLog;

/**
 * Materialize an approved upgrade once its invoice is paid.
 *
 * An upgrade is quoted and invoiced on approval but only takes effect — the
 * served order item switches to the new product and price — when the upgrade
 * invoice is settled. This listener applies that WHMCS rule on the InvoicePaid
 * event. Renewal and other order invoices never match here: the lookup is
 * scoped to a pending request whose invoice_id IS the invoice that just got
 * paid, so anything else returns without touching the payment flow.
 *
 * The listener never throws: the invoice is already settled, so a failed apply
 * is logged for admin handling with the request left pending.
 */
class ApplyUpgradeOnInvoicePaid
{
    public function handle(InvoicePaid $event): void
    {
        $invoice = $event->invoice->fresh();

        $request = UpgradeRequest::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', UpgradeRequest::STATUS_PENDING)
            ->lockForUpdate()
            ->first();

        if ($request === null) {
            return;
        }

        try {
            app(UpgradeRequestService::class)->apply($request);
        } catch (\Throwable $e) {
            AppLog::billing()->error('Upgrade apply-on-payment failed', [
                'upgrade_request_id' => $request->id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
