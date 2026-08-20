<?php

namespace App\Console\Commands;

use App\Events\DomainExpiringSoon;
use App\Models\Domain;
use Illuminate\Console\Command;

/**
 * Check for expiring domains and send notifications.
 */
class DomainExpiryCheckCommand extends Command
{
    protected $signature = 'domains:expiry-check {--days=30 : Days ahead to check}';

    protected $description = 'Notify admins and customers about expiring domains';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $expiring = Domain::where('status', 'active')
            ->where('expiry_date', '<=', now()->addDays($days))
            ->where('expiry_date', '>=', now())
            ->with('customer.user')
            ->get();

        $this->info("Found {$expiring->count()} domains expiring within {$days} days.");

        foreach ($expiring as $domain) {
            $daysLeft = $domain->daysUntilExpiry();
            $this->line("  🔔 {$domain->name} — expires in {$daysLeft} days ({$domain->customer?->full_name})");

            DomainExpiringSoon::dispatch($domain);
        }

        // Mark expired domains
        $expired = Domain::where('status', 'active')
            ->where('expiry_date', '<', now())
            ->update(['status' => 'expired']);

        if ($expired > 0) {
            $this->info("  ⚠️ Marked {$expired} domains as expired.");
        }

        return self::SUCCESS;
    }
}
