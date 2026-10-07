<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DevicePort;
use App\Models\InventoryAsset;
use App\Services\Inventory\PortDiscoveryService;
use App\Services\Modules\ModuleManager;
use FreeDSx\Snmp\Oid;
use FreeDSx\Snmp\SnmpWalk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Modules\SnmpMonitor\Console\SyncInventoryPortsCommand;
use Modules\SnmpMonitor\Jobs\PollHostBatch;
use Modules\SnmpMonitor\Models\SnmpTarget;
use Tests\Support\InteractsWithSnmpMonitorModule;
use Tests\TestCase;

// Worktrees share one composer vendor junction, so the autoloader resolves
// Tests\ against another checkout; load this suite's support trait directly.
require_once __DIR__.'/../Support/InteractsWithSnmpMonitorModule.php';

/**
 * Slice C — SNMP interface import: the live `auto_ports` hook on PollHostBatch
 * and the `snmp:sync-ports` reconcile command both drive PortDiscoveryService
 * to upsert device_ports on the linked inventory asset.
 *
 * The default scripted ifTable fixture carries no ifType, so a loopback that
 * the plan's skip rule (ifType 24) can catch is supplied per-test rather than
 * by mutating the shared harness fixture.
 */
final class SnmpPortImportTest extends TestCase
{
    use InteractsWithSnmpMonitorModule;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSnmpMonitorAutoloader();
        $this->ensureSnmpMonitoringTables(app(ModuleManager::class));
    }

    // ------------------------------------------------------------------
    // The shared ifTable fixture with ifType 24 added to `lo`, so the
    // loopback skip rule is actually exercised. eth0 stays MAC/speed-less.
    // ------------------------------------------------------------------
    private function ifTableWalkWithLoopback(): SnmpWalk
    {
        return self::fakeSnmpWalk([
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.1.1', 1),
            Oid::fromString('1.3.6.1.2.1.2.2.1.2.1', 'lo'),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.3.1', 24),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.10.1', 500),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.16.1', 700),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.1.2', 2),
            Oid::fromString('1.3.6.1.2.1.2.2.1.2.2', 'eth0'),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.10.2', 1000),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.16.2', 2000),
        ]);
    }

    // ------------------------------------------------------------------
    // The same walk after a device renumbered eth0 from ifIndex 2 to 5
    // while keeping its ifDescr, so the descr/name fallback re-matches it.
    // ------------------------------------------------------------------
    private function ifTableWalkWithRenumberedEth0(): SnmpWalk
    {
        return self::fakeSnmpWalk([
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.1.1', 1),
            Oid::fromString('1.3.6.1.2.1.2.2.1.2.1', 'lo'),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.3.1', 24),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.10.1', 500),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.16.1', 700),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.1.5', 5),
            Oid::fromString('1.3.6.1.2.1.2.2.1.2.5', 'eth0'),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.10.5', 1000),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.16.5', 2000),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function pollingTarget(array $config = ['auto_ports' => true]): SnmpTarget
    {
        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);

        return $this->makeTarget($this->makeAccount($this->makeMonitoredProduct($manager, $module, $config)));
    }

    // ------------------------------------------------------------------
    // 1. A poll imports the linked asset's interfaces: the ifType 24
    //    loopback is skipped, eth0 becomes an snmp-sourced port.
    // ------------------------------------------------------------------

    public function test_poll_imports_ports_for_the_linked_asset_and_skips_loopback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $target = $this->pollingTarget();

        $fake = $this->fakeSnmpClient();
        $fake->walks['1.3.6.1.2.1.2.2.1'] = $this->ifTableWalkWithLoopback();
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle($manager);

        $asset = InventoryAsset::query()->sole();
        $this->assertSame($asset->id, $target->fresh()->inventory_asset_id);

        $ports = DevicePort::query()->where('inventory_asset_id', $asset->id)->get();
        $this->assertSame(1, $ports->count(), 'Only the non-loopback interface becomes a port.');

        $eth0 = $ports->sole();
        $this->assertSame('eth0', $eth0->name);
        $this->assertSame('snmp', $eth0->source);
        $this->assertSame(2, $eth0->snmp_if_index);
        $this->assertSame('eth0', $eth0->snmp_if_descr);
        $this->assertNull($eth0->mac_address, 'The fixture carries no ifPhysAddress.');
        $this->assertNull($eth0->speed_bps, 'The fixture carries no ifSpeed.');
        $this->assertSame('unknown', $eth0->status, 'The fixture carries no ifAdminStatus/ifOperStatus.');
        $this->assertNotNull($eth0->last_synced_at);
    }

    // ------------------------------------------------------------------
    // 2. Idempotency: a second poll updates the same row and advances
    //    last_synced_at instead of duplicating it.
    // ------------------------------------------------------------------

    public function test_second_poll_is_idempotent_and_advances_last_synced_at(): void
    {
        Carbon::setTestNow($t0 = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $target = $this->pollingTarget();

        $fake = $this->fakeSnmpClient();
        $fake->walks['1.3.6.1.2.1.2.2.1'] = $this->ifTableWalkWithLoopback();
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle($manager);
        $first = DevicePort::query()->where('snmp_if_index', 2)->sole();
        $this->assertSame($t0->getTimestamp(), $first->last_synced_at?->getTimestamp());

        Carbon::setTestNow($t0->copy()->addSeconds(60));
        // The scripted walk is a consumable queue: re-script it for poll two.
        $fake->walks['1.3.6.1.2.1.2.2.1'] = $this->ifTableWalkWithLoopback();
        (new PollHostBatch([$target->id]))->handle($manager);

        $this->assertSame(1, DevicePort::query()->count(), 'A second poll must never duplicate the port.');
        $second = DevicePort::query()->where('snmp_if_index', 2)->sole();
        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            $t0->copy()->addSeconds(60)->getTimestamp(),
            $second->last_synced_at?->getTimestamp(),
            'A re-poll must refresh last_synced_at.'
        );
    }

    // ------------------------------------------------------------------
    // 2b. A device that renumbers ifIndex on reboot re-matches its snmp
    //     port through the descr/name fallback, which re-heals the stored
    //     snmp_if_index instead of creating a duplicate row.
    // ------------------------------------------------------------------

    public function test_renumbered_interface_heals_its_index_without_duplicating(): void
    {
        Carbon::setTestNow($t0 = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $target = $this->pollingTarget();

        $fake = $this->fakeSnmpClient();
        $fake->walks['1.3.6.1.2.1.2.2.1'] = $this->ifTableWalkWithLoopback();
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle($manager);

        $original = DevicePort::query()->where('snmp_if_index', 2)->sole();
        $this->assertSame($t0->getTimestamp(), $original->last_synced_at?->getTimestamp());

        Carbon::setTestNow($t0->copy()->addSeconds(60));
        // The scripted walk is a consumable queue: re-script it for poll two
        // with eth0 renumbered to ifIndex 5, keeping its ifDescr.
        $fake->walks['1.3.6.1.2.1.2.2.1'] = $this->ifTableWalkWithRenumberedEth0();
        (new PollHostBatch([$target->id]))->handle($manager);

        $this->assertSame(1, DevicePort::query()->count(), 'A renumbered interface must not duplicate its port.');

        $healed = DevicePort::query()->sole();
        $this->assertSame($original->id, $healed->id);
        $this->assertSame(5, $healed->snmp_if_index, 'The re-matched port must re-heal its ifIndex.');
        $this->assertSame('eth0', $healed->snmp_if_descr);
        $this->assertSame(
            $t0->copy()->addSeconds(60)->getTimestamp(),
            $healed->last_synced_at?->getTimestamp(),
            'A re-poll must refresh last_synced_at.'
        );
    }

    // ------------------------------------------------------------------
    // 3a. A human rename of an snmp-sourced port survives a re-poll (name
    //     is create-only; the row is re-matched by ifIndex).
    // ------------------------------------------------------------------

    public function test_human_rename_survives_a_re_poll(): void
    {
        Carbon::setTestNow($t0 = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $target = $this->pollingTarget();

        $fake = $this->fakeSnmpClient();
        $fake->walks['1.3.6.1.2.1.2.2.1'] = $this->ifTableWalkWithLoopback();
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle($manager);

        DevicePort::query()->where('snmp_if_index', 2)->sole()->forceFill(['name' => 'uplink-core'])->save();

        Carbon::setTestNow($t0->copy()->addSeconds(60));
        // The scripted walk is a consumable queue: re-script it for poll two.
        $fake->walks['1.3.6.1.2.1.2.2.1'] = $this->ifTableWalkWithLoopback();
        (new PollHostBatch([$target->id]))->handle($manager);

        $this->assertSame(1, DevicePort::query()->count());
        $fresh = DevicePort::query()->where('snmp_if_index', 2)->sole();
        $this->assertSame('uplink-core', $fresh->name, 'A human rename must never be overwritten by a re-poll.');
        $this->assertSame('snmp', $fresh->source);
    }

    // ------------------------------------------------------------------
    // 3b. A manual port matched by name receives only SNMP linkage
    //     backfill; every human field and `source` survives.
    // ------------------------------------------------------------------

    public function test_manual_port_is_matched_and_backfilled_with_snmp_linkage_only(): void
    {
        $asset = InventoryAsset::create(['asset_tag' => 'SW-MANUAL-01', 'asset_type' => 'switch']);

        $manual = DevicePort::create([
            'inventory_asset_id' => $asset->id,
            'name' => 'eth0',
            'port_type' => 'sfp',
            'media' => 'fiber',
            'status' => 'up',
            'source' => 'manual',
            'notes' => 'patched by hand',
            'port_number' => 'Gi1/0/24',
        ]);

        $counts = app(PortDiscoveryService::class)->syncForAsset($asset, [[
            'index' => 2,
            'name' => 'eth0',
            'descr' => 'eth0',
            'type' => 6,
            'speed' => 1000000000,
            'operStatus' => 1,
            'physAddress' => null,
        ]]);

        $manual->refresh();
        $this->assertSame(1, $counts['updated']);
        $this->assertSame('manual', $manual->source, 'A matched manual port must never flip to snmp.');
        $this->assertSame('eth0', $manual->name);
        $this->assertSame('sfp', $manual->port_type, 'Human port_type must survive.');
        $this->assertSame('fiber', $manual->media);
        $this->assertSame('patched by hand', $manual->notes);
        $this->assertSame('Gi1/0/24', $manual->port_number);
        $this->assertSame(2, $manual->snmp_if_index, 'The empty linkage column is backfilled.');
        $this->assertSame('eth0', $manual->snmp_if_descr);
        $this->assertSame('up', $manual->status);
        $this->assertNotNull($manual->last_synced_at);
    }

    // ------------------------------------------------------------------
    // 4. A richer walk maps ifType/ifSpeed/ifPhysAddress; the 4294967295
    //    unknown-ifSpeed sentinel becomes null.
    // ------------------------------------------------------------------

    public function test_custom_walk_maps_type_speed_and_mac(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $target = $this->pollingTarget();

        $fake = $this->fakeSnmpClient();
        $fake->walks['1.3.6.1.2.1.2.2.1'] = self::fakeSnmpWalk([
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.1.2', 2),
            Oid::fromString('1.3.6.1.2.1.2.2.1.2.2', 'eth0'),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.3.2', 6),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.5.2', 1000000000),
            Oid::fromString('1.3.6.1.2.1.2.2.1.6.2', 'aa:bb:cc:dd:ee:ff'),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.7.2', 1),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.8.2', 1),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.1.3', 3),
            Oid::fromString('1.3.6.1.2.1.2.2.1.2.3', 'eth1'),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.3.3', 6),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.5.3', 4294967295),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.7.3', 1),
            Oid::fromInteger('1.3.6.1.2.1.2.2.1.8.3', 2),
        ]);
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle($manager);

        $asset = InventoryAsset::query()->sole();

        $eth0 = DevicePort::query()->where('inventory_asset_id', $asset->id)->where('snmp_if_index', 2)->sole();
        $this->assertSame('ethernet', $eth0->port_type);
        $this->assertSame(1000000000, $eth0->speed_bps);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $eth0->mac_address);
        $this->assertSame('up', $eth0->status);

        $eth1 = DevicePort::query()->where('inventory_asset_id', $asset->id)->where('snmp_if_index', 3)->sole();
        $this->assertNull($eth1->speed_bps, 'The 4294967295 unknown-ifSpeed sentinel maps to null.');
        $this->assertSame('down', $eth1->status, 'operStatus 2 maps to down.');
    }

    // ------------------------------------------------------------------
    // 5. An interface absent from the next payload is marked stale, never
    //    deleted, and keeps its last real sync time.
    // ------------------------------------------------------------------

    public function test_removed_interface_marks_the_port_stale_and_retains_it(): void
    {
        $asset = InventoryAsset::create(['asset_tag' => 'SW-STALE-01', 'asset_type' => 'switch']);
        $service = app(PortDiscoveryService::class);

        $service->syncForAsset($asset, [
            ['index' => 2, 'name' => 'eth0', 'descr' => 'eth0', 'type' => 6],
        ]);

        $eth0 = DevicePort::query()->where('snmp_if_index', 2)->sole();
        $syncedAt = $eth0->last_synced_at;

        $counts = $service->syncForAsset($asset, [
            ['index' => 1, 'name' => 'lo', 'descr' => 'lo', 'type' => 24],
        ]);

        $this->assertSame(1, DevicePort::query()->count(), 'A stale port is never deleted.');
        $this->assertSame(1, $counts['stale']);

        $eth0->refresh();
        $this->assertSame('unknown', $eth0->status);
        $this->assertSame(
            $syncedAt?->getTimestamp(),
            $eth0->last_synced_at?->getTimestamp(),
            'A stale port keeps its last real sync time.'
        );
    }

    // ------------------------------------------------------------------
    // 6. The `auto_ports` toggle is all-or-nothing for port import while
    //    the poll itself (and auto_inventory) still runs.
    // ------------------------------------------------------------------

    public function test_auto_ports_toggle_off_writes_no_ports(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $target = $this->pollingTarget(['auto_ports' => false]);

        $fake = $this->fakeSnmpClient();
        $fake->walks['1.3.6.1.2.1.2.2.1'] = $this->ifTableWalkWithLoopback();
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle($manager);

        $this->assertSame(1, InventoryAsset::query()->count(), 'auto_inventory still runs.');
        $this->assertSame(0, DevicePort::query()->count(), 'auto_ports off must import nothing.');
        $this->assertSame(SnmpTarget::STATUS_UP, $target->fresh()->status, 'The toggle only affects ports.');
    }

    // ------------------------------------------------------------------
    // 7. The reconcile command: --dry-run reports without writing; the
    //    real run imports from the stored snmp_latest payload.
    // ------------------------------------------------------------------

    public function test_sync_ports_command_dry_run_then_real_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        // auto_ports off: the poll only records snmp_latest; the command imports.
        $target = $this->pollingTarget(['auto_ports' => false]);

        $fake = $this->fakeSnmpClient();
        $fake->walks['1.3.6.1.2.1.2.2.1'] = $this->ifTableWalkWithLoopback();
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle($manager);

        $asset = InventoryAsset::query()->sole();
        $this->assertSame(0, DevicePort::query()->count());

        Artisan::registerCommand(app(SyncInventoryPortsCommand::class));

        $this->artisan('snmp:sync-ports', ['--dry-run' => true])
            ->expectsOutputToContain($asset->asset_tag)
            ->assertSuccessful();
        $this->assertSame(0, DevicePort::query()->count(), '--dry-run must not write.');

        $this->artisan('snmp:sync-ports')->assertSuccessful();

        $this->assertSame(1, DevicePort::query()->count(), 'The real run imports the linked asset ports.');
        $this->assertSame(2, DevicePort::query()->sole()->snmp_if_index);
    }
}
