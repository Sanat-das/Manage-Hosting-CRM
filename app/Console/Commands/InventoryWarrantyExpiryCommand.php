<?php

namespace App\Console\Commands;

use App\Models\InventoryAsset;
use Illuminate\Console\Command;

/**
 * Check for expiring / expired inventory warranties.
 *
 * Mirrors SslExpiryCheckCommand: reports assets whose warranty falls within
 * the window and reports the already-expired count. Purely informational —
 * inventory_assets has no expiry status column, so nothing is mutated.
 */
class InventoryWarrantyExpiryCommand extends Command
{
    protected $signature = 'inventory:check-warranty-expiry {--days=30 : Days ahead to check}';

    protected $description = 'Report inventory assets with expiring warranties';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $expiring = InventoryAsset::whereNotIn('status', ['retired', 'disposed'])
            ->whereDate('warranty_expiry', '>=', today()->toDateString())
            ->whereDate('warranty_expiry', '<=', today()->addDays($days)->toDateString())
            ->orderBy('warranty_expiry')
            ->get();

        $this->info("Found {$expiring->count()} inventory assets with warranties expiring within {$days} days.");

        foreach ($expiring as $asset) {
            $daysLeft = (int) now()->diffInDays($asset->warranty_expiry, false);
            $this->line("  🛡️ {$asset->asset_tag} — warranty expires in {$daysLeft} days");
        }

        $expired = InventoryAsset::whereNotIn('status', ['retired', 'disposed'])
            ->whereDate('warranty_expiry', '<', today()->toDateString())
            ->count();

        $this->info("  ⚠️ {$expired} inventory assets already have an expired warranty.");

        return self::SUCCESS;
    }
}
