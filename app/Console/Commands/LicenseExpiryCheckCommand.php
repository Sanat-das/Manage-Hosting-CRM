<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Command;

/**
 * Check for expiring / expired licenses.
 *
 * Mirrors SslExpiryCheckCommand: reports licenses expiring within the window,
 * marks past-due active licenses as expired, and flags seat exhaustion. The
 * status is stored on the row, so the marking step is idempotent.
 */
class LicenseExpiryCheckCommand extends Command
{
    protected $signature = 'licenses:check-expiry {--days=30 : Days ahead to check}';

    protected $description = 'Report expiring licenses, mark expired ones, and flag seat exhaustion';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        // Active licenses expiring within the window.
        $expiring = License::where('status', 'active')
            ->whereDate('expiry_date', '>=', today()->toDateString())
            ->whereDate('expiry_date', '<=', today()->addDays($days)->toDateString())
            ->orderBy('expiry_date')
            ->get();

        $this->info("Found {$expiring->count()} active licenses expiring within {$days} days.");

        foreach ($expiring as $license) {
            $daysLeft = (int) now()->diffInDays($license->expiry_date, false);
            $this->line("  📄 {$license->license_type} ({$license->license_key}) — expires in {$daysLeft} days");
        }

        // Mark past-due active licenses as expired.
        $expired = License::where('status', 'active')
            ->whereDate('expiry_date', '<', today()->toDateString())
            ->update(['status' => 'expired']);

        if ($expired > 0) {
            $this->info("  ⚠️ Marked {$expired} licenses as expired.");
        }

        // Active licenses with no seats left.
        $exhausted = License::where('status', 'active')
            ->where('seats_available', '<=', 0)
            ->get();

        $this->info("Found {$exhausted->count()} active licenses with no seats available.");

        foreach ($exhausted as $license) {
            $this->line("  🪑 {$license->license_type} ({$license->license_key}) — {$license->seats_available} seats available");
        }

        return self::SUCCESS;
    }
}
