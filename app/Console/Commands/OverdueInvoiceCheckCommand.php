<?php

namespace App\Console\Commands;

use App\Events\InvoiceOverdue;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mark past-due unpaid invoices as `overdue` so the status reflects reality.
 *
 *   unpaid states: draft, sent, partial   (per STATUS_TRANSITIONS)
 *   terminal:      paid, void, cancelled  (excluded)
 *
 * The status is stored on the row (no separate `due_date_reached` flag), so
 * running the command is idempotent: invoices already at `overdue` are not
 * touched, and an invoice whose due date moves back into the future (rare, but
 * a draft could have its due date edited) is automatically corrected on the
 * next run.
 */
class OverdueInvoiceCheckCommand extends Command
{
    protected $signature = 'invoices:overdue-check';

    protected $description = 'Flip past-due unpaid invoices to overdue status';

    public function handle(): int
    {
        $now = now()->startOfDay();

        $transitioned = DB::transaction(function () use ($now) {
            // A DRAFT was never sent, so its due date means nothing: the
            // customer has not seen it. A partly paid invoice IS overdue — the
            // balance is late.
            $invoices = Invoice::whereIn('status', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL])
                ->whereNotNull('due_date')
                ->where('due_date', '<', $now)
                ->lockForUpdate()
                ->get();

            if ($invoices->isNotEmpty()) {
                Invoice::whereIn('id', $invoices->pluck('id'))
                    ->update(['status' => Invoice::STATUS_OVERDUE]);
            }

            return $invoices;
        });

        if ($transitioned->isNotEmpty()) {
            foreach ($transitioned as $invoice) {
                $invoice->status = Invoice::STATUS_OVERDUE;
                InvoiceOverdue::dispatch($invoice);
            }

            $this->info("⚠️  Marked {$transitioned->count()} past-due invoices as overdue.");
        } else {
            $this->line('No past-due invoices found.');
        }

        return self::SUCCESS;
    }
}
