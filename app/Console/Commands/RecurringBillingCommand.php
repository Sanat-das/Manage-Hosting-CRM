<?php

namespace App\Console\Commands;

use App\Jobs\GenerateInvoice;
use App\Models\HostingAccount;
use Illuminate\Console\Command;

/**
 * Generate invoices for hosting accounts with upcoming due dates.
 */
class RecurringBillingCommand extends Command
{
    protected $signature = 'billing:recurring {--days=7 : Days ahead to generate invoices}';
    protected $description = 'Generate invoices for recurring hosting services due within N days';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dueDate = now()->addDays($days);

        $accounts = HostingAccount::where('status', 'active')
            ->where('next_due_date', '<=', $dueDate)
            ->where('next_due_date', '>=', now()->startOfDay())
            ->with(['customer', 'product'])
            ->get();

        $this->info("Found {$accounts->count()} hosting accounts due within {$days} days.");

        foreach ($accounts as $account) {
            $amount = $account->product->price ?? 0;

            if ($amount > 0) {
                GenerateInvoice::dispatch(
                    $account->customer_id,
                    "Hosting renewal: {$account->domain} ({$account->product?->name})",
                    $amount,
                    $account->order_id,
                );
                $this->line("  ✅ Invoice queued for: {$account->domain} — ₹{$amount}");
            }
        }

        return self::SUCCESS;
    }
}
