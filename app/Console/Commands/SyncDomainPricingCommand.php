<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\RegistrarDriver;
use App\Exceptions\RegistrarException;
use App\Models\DomainPricing;
use App\Models\DomainSyncLog;
use App\Services\Registrars\RegistrarManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pull per-TLD pricing from each configured registrar driver into domain_pricing.
 *
 * Iterates every enabled registrar, probes the TLDs already present in
 * domain_pricing plus a built-in candidate list, upserts each price set, and
 * writes one domain_sync_log row per registrar. Always exits 0 so the schedule
 * is never tripped up by a transient registrar failure.
 */
class SyncDomainPricingCommand extends Command
{
    protected $signature = 'domains:sync-pricing';

    protected $description = 'Sync domain pricing from the configured registrar to domain_pricing';

    /**
     * TLDs probed on every run, in addition to any already stored.
     *
     * @var list<string>
     */
    private const DEFAULT_TLDS = ['com', 'net', 'org', 'in', 'co.in', 'org.in'];

    public function handle(): int
    {
        $manager = app(RegistrarManager::class);
        $enabled = $manager->enabled();

        if ($enabled === []) {
            $this->info('No configured registrar — skipping domain pricing sync.');

            DomainSyncLog::create([
                'provider' => 'none',
                'operation' => 'sync-pricing',
                'status' => 'skipped',
                'payload' => ['reason' => 'no configured registrar'],
            ]);

            return self::SUCCESS;
        }

        foreach ($enabled as $code) {
            $this->syncForRegistrar($manager, $code);
        }

        return self::SUCCESS;
    }

    /**
     * Sync pricing for a single registrar, writing one domain_sync_log row.
     */
    private function syncForRegistrar(RegistrarManager $manager, string $code): void
    {
        $driver = $manager->driver($code);

        if ($driver === null) {
            $this->info("Registrar [{$code}] resolved no driver — skipping.");

            DomainSyncLog::create([
                'provider' => $code,
                'operation' => 'sync-pricing',
                'status' => 'skipped',
                'payload' => ['reason' => 'no driver resolved'],
            ]);

            return;
        }

        try {
            $synced = $this->syncTlds($driver);

            $this->info(sprintf('Synced %d TLD(s) from registrar [%s].', count($synced), $code));

            DomainSyncLog::create([
                'provider' => $code,
                'operation' => 'sync-pricing',
                'status' => count($synced) > 0 ? 'success' : 'skipped',
                'payload' => count($synced) > 0 ? ['synced' => $synced] : ['reason' => 'all pricing null'],
            ]);
        } catch (RegistrarException $e) {
            $this->error("Registrar error for [{$code}]: ".$e->getMessage());

            DomainSyncLog::create([
                'provider' => $code,
                'operation' => 'sync-pricing',
                'status' => 'error',
                'payload' => ['synced' => []],
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->error("Unexpected error for [{$code}]: ".$e->getMessage());

            DomainSyncLog::create([
                'provider' => $code,
                'operation' => 'sync-pricing',
                'status' => 'error',
                'payload' => ['synced' => []],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Probe the candidate TLDs and upsert each non-null price set.
     *
     * @return list<string> The TLDs that were actually synced.
     */
    private function syncTlds(RegistrarDriver $driver): array
    {
        $synced = [];

        foreach ($this->candidateTlds() as $tld) {
            $pricing = $driver->getPricing($tld);

            if ($pricing === null) {
                continue;
            }

            DomainPricing::updateOrCreate(
                ['tld' => $pricing['tld']],
                [
                    'register_price' => $pricing['register'],
                    'renew_price' => $pricing['renew'],
                    'transfer_price' => $pricing['transfer'],
                    'currency' => $pricing['currency'],
                    'synced_at' => now(),
                ],
            );

            $synced[] = $pricing['tld'];

            $this->line(sprintf(
                '  %s — register %s / renew %s / transfer %s %s',
                $pricing['tld'],
                $pricing['register'],
                $pricing['renew'],
                $pricing['transfer'],
                $pricing['currency'],
            ));
        }

        return $synced;
    }

    /**
     * TLDs already stored merged with the built-in probe list, de-duplicated.
     *
     * @return list<string>
     */
    private function candidateTlds(): array
    {
        $existing = DomainPricing::query()->pluck('tld')->all();

        return array_values(array_unique(array_merge($existing, self::DEFAULT_TLDS)));
    }
}
