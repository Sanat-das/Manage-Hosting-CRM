<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InventoryAsset;
use App\Services\Modules\ModuleManager;
use FreeDSx\Snmp\Oid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\SnmpMonitor\Jobs\PollHostBatch;
use Modules\SnmpMonitor\Models\SnmpTarget;
use Tests\Support\InteractsWithSnmpMonitorModule;
use Tests\TestCase;

// Worktrees share one composer vendor junction, so the autoloader resolves
// Tests\ against another checkout; load this suite's support trait directly.
require_once __DIR__.'/../Support/InteractsWithSnmpMonitorModule.php';

/**
 * Discovered devices flow into core inventory_assets: a successful poll
 * persists the SNMP identity on the target and creates/links exactly one
 * asset, reusing it on later polls and never overwriting a human edit. The
 * whole path is gated by the `auto_inventory` toggle and isolated so an
 * inventory failure can never break or fail a poll.
 */
final class SnmpInventoryDiscoveryTest extends TestCase
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
    // Happy path: a linux host (Net-SNMP enterprise OID, no appliance match)
    // becomes a linked 'server' asset; the identity columns are persisted.
    // ------------------------------------------------------------------

    public function test_successful_poll_creates_one_linked_inventory_asset(): void
    {
        Carbon::setTestNow($t0 = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $target = $this->makeTarget($this->makeAccount($this->makeMonitoredProduct($manager, $module)));

        $this->bindCapturingCollector($this->fakeSnmpClient());

        (new PollHostBatch([$target->id]))->handle($manager);

        $asset = InventoryAsset::query()->sole();
        $this->assertSame('SNMP-LINUX-VPS-01', $asset->asset_tag);
        $this->assertSame('server', $asset->asset_type);
        $this->assertNull($asset->manufacturer);
        $this->assertSame('Discovered via SNMP poll.', $asset->notes);
        $this->assertSame('in_stock', $asset->status);

        $target = $target->fresh();
        $this->assertSame($asset->id, $target->inventory_asset_id);
        $this->assertSame('LINUX-VPS-01', $target->sys_name);
        $this->assertStringContainsString('Linux linux-vps-01', (string) $target->sys_descr);
        $this->assertSame('1.3.6.1.4.1.8072.3.2.10', $target->sys_object_id);
        $this->assertSame($t0->getTimestamp(), $target->last_discovered_at?->getTimestamp());
    }

    // ------------------------------------------------------------------
    // Enterprise map: a Cisco sysObjectID classifies the asset as a switch
    // and fills the manufacturer.
    // ------------------------------------------------------------------

    public function test_cisco_sys_object_id_maps_to_switch_with_manufacturer(): void
    {
        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $target = $this->makeTarget($this->makeAccount($this->makeMonitoredProduct($manager, $module)));

        $fake = $this->fakeSnmpClient();
        $fake->gets['1.3.6.1.2.1.1.5.0'] = Oid::fromString('1.3.6.1.2.1.1.5.0', 'CORE-SW-01');
        $fake->gets['1.3.6.1.2.1.1.2.0'] = Oid::fromOid('1.3.6.1.2.1.1.2.0', '1.3.6.1.4.1.9.1.2494');

        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle($manager);

        $asset = InventoryAsset::query()->sole();
        $this->assertSame('SNMP-CORE-SW-01', $asset->asset_tag);
        $this->assertSame('switch', $asset->asset_type);
        $this->assertSame('Cisco', $asset->manufacturer);
    }

    // ------------------------------------------------------------------
    // Reuse: a second poll must not duplicate the asset and keeps the link.
    // ------------------------------------------------------------------

    public function test_second_poll_does_not_duplicate_and_keeps_the_link(): void
    {
        Carbon::setTestNow($t0 = Carbon::parse('2026-08-25 10:00:00'));

        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $target = $this->makeTarget($this->makeAccount($this->makeMonitoredProduct($manager, $module)));

        $this->bindCapturingCollector($this->fakeSnmpClient());

        (new PollHostBatch([$target->id]))->handle($manager);
        $first = InventoryAsset::query()->sole();

        Carbon::setTestNow($t0->copy()->addSeconds(60));
        (new PollHostBatch([$target->id]))->handle($manager);

        $this->assertSame(1, InventoryAsset::query()->count(), 'A second poll must never create a duplicate asset.');
        $second = InventoryAsset::query()->sole();
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $target->fresh()->inventory_asset_id);
        $this->assertSame('2026-08-25 10:01:00', $target->fresh()->last_discovered_at?->format('Y-m-d H:i:s'));
    }

    // ------------------------------------------------------------------
    // Collision: two devices that sanitize to the same tag get -2, -3, ...
    // ------------------------------------------------------------------

    public function test_asset_tag_collision_phones_a_suffix(): void
    {
        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module);

        $first = $this->makeTarget($this->makeAccount($product), ['host' => '192.0.2.71']);
        $second = $this->makeTarget($this->makeAccount($product), ['host' => '192.0.2.72']);

        $fake = $this->fakeSnmpClient();
        $fake->gets['1.3.6.1.2.1.1.5.0'] = Oid::fromString('1.3.6.1.2.1.1.5.0', 'dup host');
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$first->id, $second->id]))->handle($manager);

        $this->assertSame(2, InventoryAsset::query()->count());

        $tags = InventoryAsset::query()->pluck('asset_tag')->all();
        sort($tags);
        $this->assertSame(['SNMP-DUP-HOST', 'SNMP-DUP-HOST-2'], $tags);

        $this->assertNotSame(
            $first->fresh()->inventory_asset_id,
            $second->fresh()->inventory_asset_id,
            'Each colliding device must link to its own asset.'
        );
    }

    // ------------------------------------------------------------------
    // Reuse: a SOFT-DELETED row holding the base tag no longer reserves it,
    // so a newly discovered device takes the base tag instead of a suffix.
    // ------------------------------------------------------------------

    public function test_a_soft_deleted_tag_does_not_force_a_suffix(): void
    {
        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module);

        $trashed = InventoryAsset::create(['asset_tag' => 'SNMP-DUP-HOST', 'asset_type' => 'server']);
        $trashed->delete();

        $target = $this->makeTarget($this->makeAccount($product), ['host' => '192.0.2.73']);

        $fake = $this->fakeSnmpClient();
        $fake->gets['1.3.6.1.2.1.1.5.0'] = Oid::fromString('1.3.6.1.2.1.1.5.0', 'dup host');
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle($manager);

        $asset = InventoryAsset::query()->sole();
        $this->assertSame('SNMP-DUP-HOST', $asset->asset_tag);
        $this->assertSame(1, InventoryAsset::query()->count());
        $this->assertSame(2, InventoryAsset::withTrashed()->count());
    }

    // ------------------------------------------------------------------
    // Human edits win: status / notes / a non-null manufacturer survive a
    // later poll untouched.
    // ------------------------------------------------------------------

    public function test_human_edited_fields_survive_a_later_poll(): void
    {
        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $target = $this->makeTarget($this->makeAccount($this->makeMonitoredProduct($manager, $module)));

        $fake = $this->fakeSnmpClient();
        $fake->gets['1.3.6.1.2.1.1.5.0'] = Oid::fromString('1.3.6.1.2.1.1.5.0', 'CORE-SW-01');
        $fake->gets['1.3.6.1.2.1.1.2.0'] = Oid::fromOid('1.3.6.1.2.1.1.2.0', '1.3.6.1.4.1.9.1.2494');
        $this->bindCapturingCollector($fake);

        (new PollHostBatch([$target->id]))->handle($manager);

        $asset = InventoryAsset::query()->sole();
        $asset->forceFill([
            'status' => 'maintenance',
            'notes' => 'racked manually',
            'manufacturer' => 'Acme Corp',
        ])->save();

        (new PollHostBatch([$target->id]))->handle($manager);

        $fresh = InventoryAsset::query()->sole();
        $this->assertSame('maintenance', $fresh->status);
        $this->assertSame('racked manually', $fresh->notes);
        $this->assertSame('Acme Corp', $fresh->manufacturer, 'A non-null human manufacturer must never be overwritten.');
    }

    // ------------------------------------------------------------------
    // Toggle off: no inventory asset, no target identity writes, poll still
    // succeeds.
    // ------------------------------------------------------------------

    public function test_auto_inventory_toggle_off_creates_nothing(): void
    {
        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $product = $this->makeMonitoredProduct($manager, $module, ['auto_inventory' => false]);
        $target = $this->makeTarget($this->makeAccount($product));

        $this->bindCapturingCollector($this->fakeSnmpClient());

        (new PollHostBatch([$target->id]))->handle($manager);

        $this->assertSame(0, InventoryAsset::query()->count());

        $fresh = $target->fresh();
        $this->assertNull($fresh->inventory_asset_id);
        $this->assertNull($fresh->sys_name);
        $this->assertNull($fresh->last_discovered_at);
        $this->assertSame(SnmpTarget::STATUS_UP, $fresh->status, 'The toggle only affects inventory, never the poll itself.');
        $this->assertSame(1, $this->monitoring()->table('snmp_host_samples')->where('host_id', $target->id)->count());
    }

    // ------------------------------------------------------------------
    // Isolation: a missing inventory store must not turn an answered host
    // into a recorded poll failure.
    // ------------------------------------------------------------------

    public function test_inventory_failure_does_not_break_a_successful_poll(): void
    {
        $manager = app(ModuleManager::class);
        $module = $this->activateSnmpMonitorModule($manager);
        $target = $this->makeTarget($this->makeAccount($this->makeMonitoredProduct($manager, $module)));

        Schema::withoutForeignKeyConstraints(fn () => Schema::drop('inventory_assets'));

        $this->bindCapturingCollector($this->fakeSnmpClient());

        (new PollHostBatch([$target->id]))->handle($manager);

        $fresh = $target->fresh();
        $this->assertSame(SnmpTarget::STATUS_UP, $fresh->status);
        $this->assertSame(0, $fresh->consecutive_failures, 'A swallowed inventory error must never count as a poll failure.');
        $this->assertSame(1, $this->monitoring()->table('snmp_host_samples')->where('host_id', $target->id)->count());
    }
}
