<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingService;
use Illuminate\Console\Command;

/**
 * Generate recurring renewal invoices for due active orders.
 *
 * Thin scheduler wrapper over BillingService::processRecurringBilling — the
 * canonical renewal engine (working from next_billing_date on Order). The
 * date the cycle advances from is fixed to today, matching the scheduled
 * one-shot run; there is no --days look-ahead window.
 */
class RecurringBillingCommand extends Command
{
    protected $signature = 'billing:recurring';

    protected $description = 'Generate recurring renewal invoices for due active orders';

    public function handle(BillingService $billing): int
    {
        $termination = $billing->processAutoTerminations();
        $result = $billing->processRecurringBilling();

        $this->info("Billing run complete: {$result['invoices_generated']} renewal invoice(s) generated, {$result['errors']} error(s).");

        if ($termination['terminated'] > 0) {
            $this->info("{$termination['terminated']} order(s) auto-terminated at the end of their fixed term.");
        }

        return self::SUCCESS;
    }
}