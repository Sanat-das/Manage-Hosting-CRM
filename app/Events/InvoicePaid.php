<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an invoice becomes fully paid.
 *
 * Fired from every settlement path (BillingService::markPaid,
 * BillingService::recordPayment, PaymentController::returned) so the
 * order lifecycle can react — see AdvanceOrderOnPayment.
 */
class InvoicePaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public Invoice $invoice) {}
}
