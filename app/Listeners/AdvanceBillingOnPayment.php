<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\InvoicePaid;
use App\Services\Billing\BillingService;
use App\Support\Logging\AppLog;

/**
 * Move each service's pay-by date onto the period its paid invoice covers.
 *
 * Generation advances only `next_invoice_date`; this is the other half of the
 * two-pointer model. It is a separate listener from AdvanceOrderOnPayment,
 * which only activates a pending order — a renewal invoice for an already
 * active order owes the schedule this advance and nothing else.
 *
 * The money has already moved when this runs, so a failure is logged for admin
 * follow-up rather than thrown back into the settlement path.
 */
class AdvanceBillingOnPayment
{
    public function __construct(private readonly BillingService $billing) {}

    public function handle(InvoicePaid $event): void
    {
        $invoice = $event->invoice->fresh();

        try {
            $this->billing->advanceBillingSchedule($invoice);
        } catch (\Throwable $e) {
            AppLog::billing()->error('Billing schedule advance failed after payment', [
                'invoice_id' => $invoice->id,
                'order_id' => $invoice->order_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
