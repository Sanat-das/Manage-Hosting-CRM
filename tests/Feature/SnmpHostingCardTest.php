<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HostingAccount;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\SnmpMonitor\Jobs\PollHostBatch;
use Modules\SnmpMonitor\Models\SnmpTarget;
use Tests\Support\InteractsWithSnmpMonitorModule;
use Tests\TestCase;

// Worktrees share one composer vendor junction, so the autoloader resolves
// Tests\ against another checkout; load this suite's support trait directly.
require_once __DIR__.'/../Support/InteractsWithSnmpMonitorModule.php';

/**
 * Plan task 7 — the hosting-account summary card replacing the removed
 * heavy panels, plus its queued manual poll trigger:
 *
 * - SnmpMonitor::hostingAccountInfo() renders the compact panel for
 *   products with an enabled snmp-monitor link (with or without prior
 *   polls), auto-creating the target through TargetService.
 * - POST admin/hosting/{hostingAccount}/snmp-monitor/poll dispatches a
 *   PollHostBatch (queued — never inline collection) and rejects products
 *   without an enabled link with 403.
 *
 * No SNMP network I/O happens anywhere in this suite.
 */
final class SnmpHostingCardTest extends TestCase
{
    use InteractsWithSnmpMonitorModule;
    use RefreshDatabase;

    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSnmpMonitorAutoloader();
        $this->ensureSnmpMonitoringTables($this->manager = app(ModuleManager::class));

        // Real production path for module routes: provider boot + route
        // registration, then refresh the router's lookup tables so route()
        // resolves names registered after the initial compilation.
        $module = $this->activateSnmpMonitorModule($this->manager);
        $instance = $this->manager->resolve($module);

        if ($instance !== null) {
            $instance->boot($this->manager->contextFor($module));
            $this->manager->registerModuleRoutes();
            app('router')->getRoutes()->refreshNameLookups();
            app('router')->getRoutes()->refreshActionLookups();
        }
    }

    // ==================================================================
    // Card rendering on admin.hosting.show
    // ==================================================================

    public function test_hosting_show_renders_snmp_card_for_linked_product_with_seeded_latest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        $account = $this->makeLinkedAccount();
        $this->makeTarget($account, ['status' => SnmpTarget::STATUS_UP]);
        $hostId = $this->targetId($account);

        $this->monitoring()->table('snmp_latest')->insert([
            'host_id' => $hostId,
            'collected_at' => '2026-08-25 11:59:00',
            'status' => 'up',
            'payload' => json_encode([
                'hostname' => 'web-01',
                'cpu_load' => 12.5,
                'cpu_source' => 'hrProcessorLoad',
                'memory_total_mb' => 4096,
                'memory_used_mb' => 2048,
            ]),
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.hosting.show', $account));

        $response->assertOk();
        $response->assertSee('SNMP Monitor');
        $response->assertSee('Open dashboard');
        $response->assertSee(route('admin.snmp-monitor.host.show', $account), false);
        // Status badge reflects target.status seeded through the latest row's target.
        $response->assertSee('Up', false);
        $response->assertSee('12.5', false);
        // Relative last-poll time instead of raw timestamps.
        $response->assertSee('minute', false);
        // Refresh button posts to the canonical hosting-prefixed poll route.
        $response->assertSee(route('admin.snmp-monitor.poll', $account), false);
    }

    public function test_hosting_show_renders_snmp_card_without_target_or_latest_and_creates_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

        $account = $this->makeLinkedAccount();

        $this->assertDatabaseMissing('snmp_targets', ['hosting_account_id' => $account->id]);

        $response = $this->actingAsAdmin()->get(route('admin.hosting.show', $account));

        $response->assertOk();
        $response->assertSee('SNMP Monitor');
        $response->assertSee('No data yet');

        // First render auto-provisions the account's target row via ensureForAccount.
        $this->assertDatabaseHas('snmp_targets', [
            'hosting_account_id' => $account->id,
            'target_os' => 'linux',
        ]);
    }

    public function test_unlinked_product_renders_no_snmp_card_section(): void
    {
        $unlinked = $this->makeAccount($this->makePlainProduct());
        $disabled = $this->makeLinkedAccount(enabled: false);

        foreach ([$unlinked, $disabled] as $account) {
            $response = $this->actingAsAdmin()->get(route('admin.hosting.show', $account));

            $response->assertOk();
            // Card-only discriminator: the module sidebar item may also say
            // "SNMP Monitor", so absence is asserted on the card's link.
            $response->assertDontSee('Open dashboard');
            $this->assertDatabaseMissing('snmp_targets', ['hosting_account_id' => $account->id]);
        }
    }

    public function test_guest_is_redirected_from_hosting_show(): void
    {
        [$account] = $this->makeMonitoredHost();

        $this->get(route('admin.hosting.show', $account))->assertRedirect();
    }

    // ==================================================================
    // Manual poll trigger: queued batch dispatch, never inline collection
    // ==================================================================

    public function test_poll_route_queues_poll_host_batch_for_the_account_target(): void
    {
        Queue::fake();

        [$account] = $this->makeMonitoredHost();
        $targetId = $this->targetId($account);

        $response = $this->actingAsAdmin()->post(
            route('admin.snmp-monitor.poll', $account)
        );

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Queued refresh');

        Queue::assertPushed(PollHostBatch::class, 1);
        Queue::assertPushed(PollHostBatch::class, function (PollHostBatch $job) use ($targetId): bool {
            return $job->targetIds === [$targetId];
        });
    }

    public function test_poll_route_creates_missing_target_before_queueing(): void
    {
        Queue::fake();

        $account = $this->makeLinkedAccount();

        $this->actingAsAdmin()->post(route('admin.snmp-monitor.poll', $account))->assertRedirect();

        $targetId = (int) DB::table('snmp_targets')
            ->where('hosting_account_id', $account->id)
            ->value('id');

        Queue::assertPushed(PollHostBatch::class, fn (PollHostBatch $job) => $job->targetIds === [$targetId]);
    }

    public function test_poll_route_rejects_products_without_enabled_link_with_403(): void
    {
        Queue::fake();

        $unlinked = $this->makeAccount($this->makePlainProduct());
        $disabled = $this->makeLinkedAccount(enabled: false);

        foreach ([$unlinked, $disabled] as $account) {
            $this->actingAsAdmin()
                ->post(route('admin.snmp-monitor.poll', $account))
                ->assertForbidden();
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('snmp_targets', ['hosting_account_id' => $unlinked->id]);
    }

    public function test_guest_is_redirected_from_poll_route(): void
    {
        [$account] = $this->makeMonitoredHost();

        $this->post(route('admin.snmp-monitor.poll', $account))->assertRedirect();
    }

    // ==================================================================
    // Host + poll interval update
    // ==================================================================

    public function test_host_update_route_saves_explicit_host_and_interval(): void
    {
        [$account] = $this->makeMonitoredHost();
        $this->leaseIp($account, '203.0.113.20');

        $response = $this->actingAsAdmin()->post(
            route('admin.snmp-monitor.host.update', $account),
            ['host' => '203.0.113.20', 'poll_interval' => 900],
        );

        $response->assertRedirect();
        $response->assertSessionHas('success', 'SNMP host updated to 203.0.113.20.');

        $this->assertDatabaseHas('snmp_targets', [
            'hosting_account_id' => $account->id,
            'host' => '203.0.113.20',
            'poll_interval' => 900,
        ]);
    }

    public function test_host_update_route_with_empty_interval_clears_override_to_inherit_product_default(): void
    {
        [$account] = $this->makeMonitoredHost();
        $this->leaseIp($account, '203.0.113.21');
        SnmpTarget::where('hosting_account_id', $account->id)->update(['poll_interval' => 60]);

        $this->actingAsAdmin()->post(
            route('admin.snmp-monitor.host.update', $account),
            ['host' => '203.0.113.21', 'poll_interval' => ''],
        )->assertRedirect();

        $this->assertDatabaseHas('snmp_targets', [
            'hosting_account_id' => $account->id,
            'poll_interval' => null,
        ]);
    }

    public function test_host_update_route_rearms_the_schedule_when_the_interval_changes(): void
    {
        [$account] = $this->makeMonitoredHost();
        $this->leaseIp($account, '203.0.113.23');
        SnmpTarget::where('hosting_account_id', $account->id)
            ->update(['poll_interval' => 3600, 'next_poll_at' => now()->addMinutes(50)]);

        $this->actingAsAdmin()->post(
            route('admin.snmp-monitor.host.update', $account),
            ['host' => '203.0.113.23', 'poll_interval' => 60],
        )->assertRedirect();

        $target = SnmpTarget::where('hosting_account_id', $account->id)->firstOrFail();

        $this->assertSame(60, $target->poll_interval);
        $this->assertNull(
            $target->next_poll_at,
            'A cadence change must re-arm the target — next_poll_at still held the moment computed from the old interval.'
        );
    }

    public function test_host_update_route_keeps_the_schedule_when_the_interval_is_unchanged(): void
    {
        [$account] = $this->makeMonitoredHost();
        $this->leaseIp($account, '203.0.113.24');
        $scheduled = now()->addMinutes(4)->startOfSecond();
        SnmpTarget::where('hosting_account_id', $account->id)
            ->update(['poll_interval' => 60, 'next_poll_at' => $scheduled]);

        $this->actingAsAdmin()->post(
            route('admin.snmp-monitor.host.update', $account),
            ['host' => '203.0.113.24', 'poll_interval' => 60],
        )->assertRedirect();

        $target = SnmpTarget::where('hosting_account_id', $account->id)->firstOrFail();

        $this->assertSame(
            $scheduled->getTimestamp(),
            $target->next_poll_at?->getTimestamp(),
            'Editing only the host must not disturb an already-claimed schedule.'
        );
    }

    public function test_host_update_route_rejects_interval_below_the_scheduler_floor(): void
    {
        [$account] = $this->makeMonitoredHost();
        $this->leaseIp($account, '203.0.113.22');

        $this->actingAsAdmin()->post(
            route('admin.snmp-monitor.host.update', $account),
            ['host' => '203.0.113.22', 'poll_interval' => 30],
        )->assertSessionHasErrors('poll_interval');

        $this->assertDatabaseHas('snmp_targets', [
            'hosting_account_id' => $account->id,
            'host' => '192.0.2.50', // unchanged — validation failed before save
        ]);
    }

    // ==================================================================
    // Target deletion
    // ==================================================================

    public function test_delete_route_removes_the_target_and_all_of_its_samples(): void
    {
        [$account] = $this->makeMonitoredHost();
        $targetId = $this->targetId($account);

        // Samples live on the separate monitoring connection with no foreign
        // key back to snmp_targets, so they must be removed explicitly or a
        // recycled host_id inherits another host's history.
        $this->monitoring()->table('snmp_latest')->insert([
            'host_id' => $targetId, 'collected_at' => now()->format('Y-m-d H:i:s.v'),
            'status' => 'up', 'payload' => json_encode(['hostname' => 'doomed-host']),
        ]);
        $this->monitoring()->table('snmp_host_samples')->insert([
            'host_id' => $targetId, 'collected_at' => now()->format('Y-m-d H:i:s.v'), 'cpu_pct' => 10.0,
        ]);
        $this->monitoring()->table('snmp_if_samples')->insert([
            'host_id' => $targetId, 'if_index' => 1,
            'collected_at' => now()->format('Y-m-d H:i:s.v'), 'in_octets' => 100, 'out_octets' => 200,
        ]);

        $this->actingAsAdmin()
            ->delete(route('admin.snmp-monitor.target.destroy', $account))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('snmp_targets', ['id' => $targetId]);
        foreach (['snmp_latest', 'snmp_host_samples', 'snmp_if_samples'] as $table) {
            $this->assertSame(
                0,
                $this->monitoring()->table($table)->where('host_id', $targetId)->count(),
                "Orphaned rows left behind in [{$table}]."
            );
        }
    }

    public function test_delete_route_requires_the_manage_permission(): void
    {
        [$account] = $this->makeMonitoredHost();
        $targetId = $this->targetId($account);

        $this->actingAsAdminWithoutManage()
            ->delete(route('admin.snmp-monitor.target.destroy', $account))
            ->assertForbidden();

        $this->assertDatabaseHas('snmp_targets', ['id' => $targetId]);
    }

    public function test_delete_route_reports_when_there_is_no_target(): void
    {
        [$account] = $this->makeMonitoredHost();
        SnmpTarget::where('hosting_account_id', $account->id)->delete();

        $this->actingAsAdmin()
            ->delete(route('admin.snmp-monitor.target.destroy', $account))
            ->assertSessionHasErrors('target');
    }

    public function test_delete_route_is_refused_for_an_unlinked_account(): void
    {
        $unlinked = $this->makeAccount($this->makePlainProduct());

        $this->actingAsAdmin()
            ->delete(route('admin.snmp-monitor.target.destroy', $unlinked))
            ->assertForbidden();
    }

    public function test_host_update_route_rejects_ip_not_assigned_to_the_account(): void
    {
        [$account] = $this->makeMonitoredHost();

        $this->actingAsAdmin()->post(
            route('admin.snmp-monitor.host.update', $account),
            ['host' => '198.51.100.5'],
        )->assertSessionHasErrors('host');
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * A linked product (snmp-monitor enabled) that also carries an enabled
     * ssh-console link so target_os derives to "linux" exactly like production
     * VPS products do.
     */
    private function makeLinkedAccount(bool $enabled = true): HostingAccount
    {
        $module = $this->activateSnmpMonitorModule($this->manager);
        $product = $this->makeMonitoredProduct($this->manager, $module);

        if (! $enabled) {
            ProductModule::query()
                ->where('product_id', $product->id)
                ->where('module_id', $module->id)
                ->update(['enabled' => false]);
        } else {
            $this->linkOsModule($product, 'ssh-console');
        }

        return $this->makeAccount($product);
    }

    private function makeMonitoredHost(): array
    {
        $module = $this->activateSnmpMonitorModule($this->manager);
        $product = $this->makeMonitoredProduct($this->manager, $module);
        $this->linkOsModule($product, 'ssh-console');
        $account = $this->makeAccount($product);
        $this->makeTarget($account);

        return [$account];
    }

    private function linkOsModule(Product $product, string $slug): void
    {
        $osModule = Module::query()->where('slug', $slug)->first();

        if ($osModule !== null) {
            ProductModule::create([
                'product_id' => $product->id,
                'module_id' => $osModule->id,
                'enabled' => true,
                'config' => [],
            ]);
        }
    }

    private function makePlainProduct(): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::create(['name' => "Unlinked Card Product {$sequence}"]);
    }

    private function targetId(HostingAccount $account): int
    {
        return (int) DB::table('snmp_targets')->where('hosting_account_id', $account->id)->value('id');
    }

    private function leaseIp(HostingAccount $account, string $address, string $networkType = 'public'): IpAddress
    {
        static $sequence = 0;
        $sequence++;

        $subnet = IpSubnet::create([
            'name' => "SNMP Card Subnet {$sequence}",
            'subnet_cidr' => "203.0.{$sequence}.0/24",
            'network_type' => $networkType,
        ]);

        return IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => $address,
            'type' => 'assigned',
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAsAdminWith(['hosting.view' => 'Hosting view', 'hosting.manage' => 'Hosting manage']);
    }

    /**
     * Read-only admin: proves the destructive routes are gated on
     * hosting.manage rather than on merely being an admin.
     */
    private function actingAsAdminWithoutManage(): self
    {
        return $this->actingAsAdminWith(['hosting.view' => 'Hosting view']);
    }

    /**
     * @param  array<string, string>  $permissions  name => label
     */
    private function actingAsAdminWith(array $permissions): self
    {
        $user = User::factory()->create();
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach ($permissions as $name => $label) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $label]);
            $adminRole->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
