<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an invoice becomes overdue.
 *
 * The recurring billing flow (BillingService::processRecurringBilling) is the
 * hook point: a freshly generated invoice whose due date has already passed is
 * treated as overdue at birth. Listeners notify the customer via the database
 * channel, gated through NotificationPreferenceService.
 */
class InvoiceOverdue
{
    use Dispatchable, SerializesModels;

    public function __construct(public Invoice $invoice) {}
}
