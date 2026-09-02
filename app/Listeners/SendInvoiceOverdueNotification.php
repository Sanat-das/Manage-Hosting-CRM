<?php

namespace App\Listeners;

use App\Events\InvoiceOverdue;
use App\Models\Customer;
use App\Notifications\InvoiceOverdueNotification;
use App\Services\NotificationPreferenceService;

/**
 * Notify the customer when an invoice becomes overdue.
 *
 * Gated through NotificationPreferenceService (type "invoice.overdue").
 */
class SendInvoiceOverdueNotification
{
    public function __construct(private readonly NotificationPreferenceService $prefs) {}

    public function handle(InvoiceOverdue $event): void
    {
        $invoice = $event->invoice->fresh(['customer']);
        $customer = $invoice->customer;

        if (! $customer instanceof Customer) {
            return;
        }

        if ($this->prefs->isEnabled($customer, 'invoice.overdue')) {
            $customer->notify(new InvoiceOverdueNotification($invoice));
        }
    }
}
