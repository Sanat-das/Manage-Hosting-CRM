<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Console;

use App\Models\InventoryAsset;
use App\Services\Inventory\PortDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\SnmpMonitor\Models\SnmpTarget;
use Throwable;

/**
 * Reconciles SNMP interfaces into device ports for every monitored target
 * that is linked to a core inventory asset.
 *
 * This is the backfill/repair path for the live `auto_ports` hook: it reads
 * the last collected payload from `snmp_latest` (monitoring connection) and
 * runs the same idempotent import. Safe to run repeatedly; `--dry-run`
 * reports the planned create/update/stale counts without writing anything.
 */
final class SyncInventoryPortsCommand extends Command
{
    /** @var string */
    protected $signature = 'snmp:sync-ports
        {--target=* : Sync only these SNMP target ids}
        {--asset=* : Sync only these inventory asset ids}
        {--dry-run : Report planned changes without writing}';

    /** @var string */
    protected $description = 'Import SNMP interfaces into device ports for linked inventory assets';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targets = $this->targets(
            $this->intList((array) $this->option('target')),
            $this->intList((array) $this->option('asset')),
        );

        if ($targets->isEmpty()) {
            $this->info('No SNMP targets with a linked inventory asset to sync.');

            return self::SUCCESS;
        }

        $service = app(PortDiscoveryService::class);
        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'stale' => 0];
        $processed = 0;

        foreach ($targets as $target) {
            $asset = InventoryAsset::query()->find((int) $target->inventory_asset_id);

            if ($asset === null) {
                continue;
            }

            $interfaces = $this->interfacesFor((int) $target->id);

            if ($interfaces === []) {
                continue;
            }

            try {
                $counts = $service->syncForAsset($asset, $interfaces, $dryRun);
            } catch (Throwable $e) {
                $this->error(sprintf('%s: sync failed — %s', $asset->asset_tag, $e->getMessage()));

                continue;
            }

            $processed++;

            foreach ($totals as $key => $value) {
                $totals[$key] += $counts[$key];
            }

            $this->line(sprintf(
                '%s: %d created, %d updated, %d stale%s',
                $asset->asset_tag,
                $counts['created'],
                $counts['updated'],
                $counts['stale'],
                $dryRun ? ' (dry run)' : '',
            ));
        }

        $this->info(sprintf(
            '%s: %d asset(s) — %d created, %d updated, %d skipped, %d stale.',
            $dryRun ? 'Planned' : 'Synced',
            $processed,
            $totals['created'],
            $totals['updated'],
            $totals['skipped'],
            $totals['stale'],
        ));

        return self::SUCCESS;
    }

    /**
     * Targets in scope: linked to an inventory asset, narrowed by the
     * optional --target / --asset id filters.
     *
     * @param  list<int>  $targetIds
     * @param  list<int>  $assetIds
     * @return Collection<int, SnmpTarget>
     */
    private function targets(array $targetIds, array $assetIds): Collection
    {
        $query = SnmpTarget::query()->whereNotNull('inventory_asset_id');

        if ($targetIds !== []) {
            $query->whereIn('id', $targetIds);
        }

        if ($assetIds !== []) {
            $query->whereIn('inventory_asset_id', $assetIds);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Decode the target's last collected payload interfaces from snmp_latest.
     *
     * @return array<int, array<string, mixed>>
     */
    private function interfacesFor(int $targetId): array
    {
        $row = DB::connection('monitoring')->table('snmp_latest')->where('host_id', $targetId)->first();

        if ($row === null) {
            return [];
        }

        $payload = json_decode((string) $row->payload, true);
        $interfaces = is_array($payload) ? ($payload['interfaces'] ?? []) : [];

        return is_array($interfaces) ? $interfaces : [];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function intList(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $value): int => (int) $value, $values),
            static fn (int $value): bool => $value > 0,
        ));
    }
}
