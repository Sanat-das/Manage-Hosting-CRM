<?php

namespace App\Listeners;

use App\Events\InvoiceOverdue;
use App\Models\Customer;
use App\Notifications\InvoiceOverdueNotification;
use App\Services\InvoiceEmailService;
use App\Services\NotificationPreferenceService;
use App\Support\Logging\AppLog;

/**
 * Notify the customer when an invoice becomes overdue.
 *
 * Both channels are gated through NotificationPreferenceService
 * (type "invoice.overdue"), independently: the in-app database notification
 * and the 'invoice_overdue_reminder' email. Mail failure must not bubble into
 * the command that dispatched the event, so it is logged and swallowed.
 */
class SendInvoiceOverdueNotification
{
    public function __construct(
        private readonly NotificationPreferenceService $prefs,
        private readonly InvoiceEmailService $invoiceEmails,
    ) {}

    public function handle(InvoiceOverdue $event): void
    {
        $invoice = $event->invoice->fresh(['customer']);
        $customer = $invoice->customer;

        if (! $customer instanceof Customer) {
            return;
        }

        if ($this->prefs->isEnabled($customer, 'invoice.overdue', 'database')) {
            try {
                $customer->notify(new InvoiceOverdueNotification($invoice));
            } catch (\Throwable $e) {
                AppLog::billing()->error('Invoice overdue notification failed to send; continuing.', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($this->prefs->isEnabled($customer, 'invoice.overdue', 'mail')) {
            try {
                $this->invoiceEmails->send($invoice, 'invoice_overdue_reminder');
            } catch (\Throwable $e) {
                AppLog::billing()->error('Invoice overdue email failed to dispatch; continuing.', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
