<?php

namespace App\Listeners;

use App\Events\InvoicePaid;
use App\Models\Customer;
use App\Services\InvoiceEmailService;

/**
 * Email the customer a receipt when an invoice is fully settled.
 *
 * Thin listener: the notification matrix gate (invoice.paid / customer /
 * mail) lives inside InvoiceEmailService::send(), which also logs its skips.
 */
class SendPaymentReceivedNotification
{
    public function __construct(private readonly InvoiceEmailService $invoiceEmails) {}

    public function handle(InvoicePaid $event): void
    {
        $invoice = $event->invoice->fresh(['customer']);

        if (! $invoice?->customer instanceof Customer) {
            return;
        }

        $this->invoiceEmails->send($invoice, 'payment_received');
    }
}
