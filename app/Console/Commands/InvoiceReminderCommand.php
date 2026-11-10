<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceEmailService;
use App\Services\NotificationPreferenceService;
use App\Support\AppSettings;
use Illuminate\Console\Command;

/**
 * Chase invoices that are past due and still unpaid.
 *
 * `invoices:overdue-check` flips a past-due invoice to 'overdue' once, and
 * the overdue listener sends the customer a single reminder at that moment.
 * Nothing chased the invoice afterwards, so a customer who ignored the first
 * email was never asked again — and the columns that carry a reminder
 * cadence (`invoices.last_reminder_at`, `invoices.reminder_count`) were never
 * written by anything.
 *
 * This command runs the ladder: every past-due invoice that is unpaid and not
 * in a terminal state is reminded again once `automation_invoice_reminder_days`
 * have passed since the last reminder. The customer's notification
 * preferences gate the mail exactly as they gate the first reminder
 * ('invoice.overdue' → mail), and `automation_invoice_reminders` switches the
 * whole ladder off.
 */
class InvoiceReminderCommand extends Command
{
    protected $signature = 'invoices:remind';

    protected $description = 'Send a reminder for every invoice that is past due and still unpaid';

    public function handle(InvoiceEmailService $invoiceEmails, NotificationPreferenceService $prefs): int
    {
        if (! AppSettings::bool('automation_invoice_reminders', true)) {
            $this->info('Invoice reminders are switched off.');

            return self::SUCCESS;
        }

        $days = max(0, (int) AppSettings::get('automation_invoice_reminder_days', '3'));
        $cutoff = now()->subDays($days);

        $invoices = Invoice::query()
            ->whereIn('status', [Invoice::STATUS_SENT, Invoice::STATUS_OVERDUE, Invoice::STATUS_PARTIAL])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today()->toDateString())
            ->where(function ($query) {
                // Unpaid: nothing collected, or only part of it.
                $query->where('paid_amount', '<=', 0)
                    ->orWhere('status', Invoice::STATUS_PARTIAL);
            })
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_reminder_at')
                    ->orWhere('last_reminder_at', '<=', $cutoff);
            })
            ->with('customer.user')
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($invoices as $invoice) {
            $customer = $invoice->customer;

            if ($customer === null || ! $prefs->isEnabled($customer, 'invoice.overdue', 'mail')) {
                $skipped++;

                continue;
            }

            if (! $invoiceEmails->send($invoice, 'invoice_overdue_reminder')) {
                $skipped++;

                continue;
            }

            $invoice->update([
                'reminder_count' => (int) $invoice->reminder_count + 1,
                'last_reminder_at' => now(),
            ]);

            $sent++;
        }

        $this->info("Invoice reminders: {$sent} sent, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
