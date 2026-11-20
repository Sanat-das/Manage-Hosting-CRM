<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;

/**
 * Check for expiring / expired device warranties.
 *
 * Mirrors SslExpiryCheckCommand: reports devices whose warranty falls within
 * the window and reports the already-expired count. Purely informational —
 * devices has no expiry status column, so nothing is mutated.
 */
class DeviceWarrantyExpiryCommand extends Command
{
    protected $signature = 'devices:check-warranty-expiry {--days=30 : Days ahead to check}';

    protected $description = 'Report devices with expiring warranties';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $expiring = Device::whereNotIn('status', ['retired', 'disposed'])
            ->whereDate('warranty_expiry', '>=', today()->toDateString())
            ->whereDate('warranty_expiry', '<=', today()->addDays($days)->toDateString())
            ->orderBy('warranty_expiry')
            ->get();

        $this->info("Found {$expiring->count()} devices with warranties expiring within {$days} days.");

        foreach ($expiring as $device) {
            $daysLeft = (int) now()->diffInDays($device->warranty_expiry, false);
            $this->line("  🛡️ {$device->asset_tag} — warranty expires in {$daysLeft} days");
        }

        $expired = Device::whereNotIn('status', ['retired', 'disposed'])
            ->whereDate('warranty_expiry', '<', today()->toDateString())
            ->count();

        $this->info("  ⚠️ {$expired} devices already have an expired warranty.");

        return self::SUCCESS;
    }
}
