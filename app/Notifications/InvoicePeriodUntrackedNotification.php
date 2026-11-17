<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification: an invoice line claims no billing period.
 *
 * Raised by InvoicePeriodCheckCommand for every matrix-enabled staff user,
 * so the staff inbox learns about an open invoice that the renewal schedule
 * cannot see: the duplicate-billing net matches open invoices by
 * `billed_due_date`, and the schedule advance skips a line without a period,
 * so such an invoice neither blocks a renewal nor advances the schedule when
 * it is paid.
 *
 * Nothing is queued — the caller gates the dispatch per finding per day
 * through the cache.
 */
class InvoicePeriodUntrackedNotification extends Notification
{
    use Queueable;

    public function __construct(public Invoice $invoice, public int $findingsCount) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $customerName = (string) ($this->invoice->customer?->full_name ?? 'Customer');
        $invoiceNo = (string) $this->invoice->invoice_no;

        return new DatabaseMessage([
            'type' => 'invoice.period_untracked',
            'invoice_id' => (int) $this->invoice->id,
            'invoice_no' => $invoiceNo,
            'customer_name' => $customerName,
            'findings_count' => $this->findingsCount,
            'message' => "Invoice {$invoiceNo} ({$customerName}) has {$this->findingsCount} line(s) with no billing period.",
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable)->data;
    }
}
