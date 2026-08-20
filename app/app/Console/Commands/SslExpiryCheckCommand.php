<?php

namespace App\Console\Commands;

use App\Models\SslCertificate;
use Illuminate\Console\Command;

/**
 * Check for expiring / expired SSL certificates.
 *
 * Mirrors DomainExpiryCheckCommand: reports certs expiring within the window
 * and marks past-due active certificates as expired. Fills the plan's
 * `ssl:check-expiry` gap (2 of the original 6 scheduled commands).
 */
class SslExpiryCheckCommand extends Command
{
    protected $signature = 'ssl:check-expiry {--days=30 : Days ahead to check}';

    protected $description = 'Report expiring SSL certificates and mark expired ones';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $expiring = SslCertificate::where('status', 'active')
            ->where('expiry_date', '<=', now()->addDays($days)->endOfDay())
            ->where('expiry_date', '>=', now())
            ->with('customer')
            ->orderBy('expiry_date')
            ->get();

        $this->info("Found {$expiring->count()} SSL certificates expiring within {$days} days.");

        foreach ($expiring as $cert) {
            $daysLeft = (int) now()->diffInDays($cert->expiry_date, false);
            $this->line(
                "  🔐 {$cert->domain_name} — expires in {$daysLeft} days"
                .($cert->customer?->full_name ? " ({$cert->customer->full_name})" : '')
            );
        }

        // Mark past-due active certificates as expired.
        $expired = SslCertificate::where('status', 'active')
            ->where('expiry_date', '<', now())
            ->update(['status' => 'expired']);

        if ($expired > 0) {
            $this->info("  ⚠️ Marked {$expired} SSL certificates as expired.");
        }

        return self::SUCCESS;
    }
}
