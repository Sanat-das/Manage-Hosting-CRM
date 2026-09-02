<?php

namespace App\Console\Commands;

use App\Models\HostingAccount;
use Illuminate\Console\Command;

/**
 * Sync hosting account usage data from server panels (cPanel, etc.).
 */
class UsageSyncCommand extends Command
{
    protected $signature = 'hosting:usage-sync';

    protected $description = 'Sync disk and bandwidth usage for all active hosting accounts';

    public function handle(): int
    {
        $accounts = HostingAccount::where('status', 'active')->get();

        $this->info("Syncing usage for {$accounts->count()} active hosting accounts.");

        foreach ($accounts as $account) {
            // TODO: Session 5 — actual cPanel/panel API integration
            // For now, just log
            $this->line("  📊 {$account->domain} — disk: {$account->disk_used}/{$account->disk_quota} MB, bw: {$account->bandwidth_used}/{$account->bandwidth_quota} MB");
        }

        $this->info('Usage sync complete (stub — panel API not yet integrated).');

        return self::SUCCESS;
    }
}
