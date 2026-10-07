<?php

namespace Tests\Feature;

use App\Models\HostingAccount;
use App\Models\InventoryAsset;
use App\Models\IpAddress;
use App\Models\IpAllocationHistory;
use App\Models\IpSubnet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IP Manager linkage on the inventory asset create/edit forms.
 *
 * Types server and switch link existing ip_addresses rows via
 * ip_addresses.inventory_asset_id; IPs are never inventory assets. The forms
 * post ip_picker_present + ip_address_ids[] and the controller syncs the links.
 */
class InventoryAssetIpLinkTest extends TestCase
{
    use RefreshDatabase;

    private int $subnetSequence = 0;

    private int $ipSequence = 0;

    private int $accountSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'inventory.view'], ['label' => 'View Inventory']);
        $manage = Permission::firstOrCreate(['name' => 'inventory.manage'], ['label' => 'Manage Inventory']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(string $assetTag, array $overrides = []): InventoryAsset
    {
        return InventoryAsset::create(array_merge([
            'asset_tag' => $assetTag,
            'asset_type' => 'server',
        ], $overrides));
    }

    private function makeSubnet(string $name = 'Edge Subnet', ?Vlan $vlan = null): IpSubnet
    {
        $this->subnetSequence++;

        return IpSubnet::create([
            'name' => $name,
            'subnet_cidr' => "10.95.{$this->subnetSequence}.0/24",
            'network_type' => 'private',
            'vlan_id' => $vlan?->id,
        ]);
    }

    private function makeVlan(string $name, int $vlanId): Vlan
    {
        return Vlan::create([
            'name' => $name,
            'vlan_id' => $vlanId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeIp(IpSubnet $subnet, string $address, array $overrides = []): IpAddress
    {
        $this->ipSequence++;

        return IpAddress::create(array_merge([
            'subnet_id' => $subnet->id,
            'ip_address' => $address,
        ], $overrides));
    }

    private function makeAccount(): HostingAccount
    {
        $this->accountSequence++;

        return HostingAccount::create([
            'customer_id' => $this->accountSequence,
            'product_id' => $this->accountSequence,
            'username' => "acct{$this->accountSequence}",
        ]);
    }

    private function runInventoryAssetIpMigration(): void
    {
        $migration = require database_path('migrations/2026_10_06_000002_normalize_inventory_asset_ip_assignments.php');

        $migration->up();
    }

    public function test_create_links_every_picked_ip(): void
    {
        $subnet = $this->makeSubnet();
        $ipA = $this->makeIp($subnet, '10.95.1.10');
        $ipB = $this->makeIp($subnet, '10.95.1.11');

        $response = $this->actingAsAdmin()->post(route('admin.inventory-assets.store'), [
            'asset_tag' => 'IP-CREATE-1',
            'asset_type' => 'server',
            'ip_picker_present' => '1',
            'ip_address_ids' => [$ipA->id, $ipB->id],
        ]);

        $response->assertRedirect(route('admin.inventory-assets.index'));
        $response->assertSessionHasNoErrors();

        $asset = InventoryAsset::where('asset_tag', 'IP-CREATE-1')->firstOrFail();
        $this->assertSame($asset->id, $ipA->fresh()->inventory_asset_id);
        $this->assertSame($asset->id, $ipB->fresh()->inventory_asset_id);
    }

    public function test_create_without_any_ips_succeeds(): void
    {
        $response = $this->actingAsAdmin()->post(route('admin.inventory-assets.store'), [
            'asset_tag' => 'IP-NOIPS-1',
            'asset_type' => 'server',
            'ip_picker_present' => '1',
        ]);

        $response->assertRedirect(route('admin.inventory-assets.index'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_assets', ['asset_tag' => 'IP-NOIPS-1']);
    }

    public function test_edit_sync_attaches_and_detaches_links(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-SYNC-1');
        $keep = $this->makeIp($subnet, '10.95.2.10', ['inventory_asset_id' => $asset->id]);
        $drop = $this->makeIp($subnet, '10.95.2.11', ['inventory_asset_id' => $asset->id]);
        $add = $this->makeIp($subnet, '10.95.2.12');

        $response = $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), [
            'asset_tag' => 'IP-SYNC-1',
            'ip_picker_present' => '1',
            'ip_address_ids' => [$keep->id, $add->id],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($asset->id, $keep->fresh()->inventory_asset_id);
        $this->assertSame($asset->id, $add->fresh()->inventory_asset_id);
        $this->assertNull($drop->fresh()->inventory_asset_id);
    }

    public function test_ip_already_linked_to_another_asset_is_rejected_and_nothing_changes(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-REJECT-1');
        $other = $this->makeAsset('IP-REJECT-OTHER');
        $taken = $this->makeIp($subnet, '10.95.3.10', ['inventory_asset_id' => $other->id]);
        $mine = $this->makeIp($subnet, '10.95.3.11', ['inventory_asset_id' => $asset->id]);

        $response = $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), [
            'asset_tag' => 'IP-REJECT-1',
            'ip_picker_present' => '1',
            'ip_address_ids' => [$taken->id],
        ]);

        $response->assertSessionHasErrors([
            'ip_address_ids.0' => 'This IP is already linked to another asset.',
        ]);

        // Validation fails before the sync, so no link changes at all.
        $this->assertSame($other->id, $taken->fresh()->inventory_asset_id);
        $this->assertSame($asset->id, $mine->fresh()->inventory_asset_id);
    }

    public function test_update_without_the_picker_marker_leaves_links_untouched(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-NOMARK-1');
        $linked = $this->makeIp($subnet, '10.95.4.10', ['inventory_asset_id' => $asset->id]);
        $free = $this->makeIp($subnet, '10.95.4.11');

        $response = $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), [
            'model' => 'API-style update',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($asset->id, $linked->fresh()->inventory_asset_id);
        $this->assertNull($free->fresh()->inventory_asset_id);
    }

    public function test_detach_unlinks_ip_and_redirects_back(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-DETACH-1');
        $ip = $this->makeIp($subnet, '10.95.5.10', ['inventory_asset_id' => $asset->id]);

        $response = $this->actingAsAdmin()
            ->from(route('admin.inventory-assets.show', $asset))
            ->delete(route('admin.inventory-assets.detach-ip', [$asset, $ip]));

        $response->assertRedirect(route('admin.inventory-assets.show', $asset));
        $response->assertSessionHas('success', 'IP unlinked from asset.');
        $this->assertNull($ip->fresh()->inventory_asset_id);
    }

    public function test_detach_returns_404_when_ip_is_not_linked_to_the_asset(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-DETACH-404');
        $other = $this->makeAsset('IP-DETACH-OTHER');
        $ip = $this->makeIp($subnet, '10.95.6.10', ['inventory_asset_id' => $other->id]);

        $this->actingAsAdmin()
            ->delete(route('admin.inventory-assets.detach-ip', [$asset, $ip]))
            ->assertNotFound();

        $this->assertSame($other->id, $ip->fresh()->inventory_asset_id);
    }

    public function test_detach_is_forbidden_without_manage_permission(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-DETACH-FORBID');
        $ip = $this->makeIp($subnet, '10.95.7.10', ['inventory_asset_id' => $asset->id]);

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $adminRole->permissions()->detach(
            Permission::firstOrCreate(['name' => 'inventory.manage'], ['label' => 'Manage Inventory'])->id,
        );

        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->delete(route('admin.inventory-assets.detach-ip', [$asset, $ip]))
            ->assertForbidden();

        $this->assertSame($asset->id, $ip->fresh()->inventory_asset_id);
    }

    public function test_search_matches_ip_subnet_name_and_ptr(): void
    {
        $subnet = $this->makeSubnet('Edge Subnet');
        $ip = $this->makeIp($subnet, '10.95.8.5', ['ptr_record' => 'edge.example.test']);

        foreach (['10.95.8.5', 'Edge Subnet', 'edge.example'] as $query) {
            $this->actingAsAdmin()
                ->getJson(route('admin.ip-addresses.search', ['q' => $query]))
                ->assertOk()
                ->assertJsonPath('results.0.id', $ip->id);
        }
    }

    public function test_search_blank_query_returns_no_results(): void
    {
        $this->makeIp($this->makeSubnet(), '10.95.9.5');

        $this->actingAsAdmin()
            ->getJson(route('admin.ip-addresses.search'))
            ->assertOk()
            ->assertJsonPath('results', []);

        $this->actingAsAdmin()
            ->getJson(route('admin.ip-addresses.search', ['q' => '   ']))
            ->assertOk()
            ->assertJsonPath('results', []);
    }

    public function test_search_results_are_capped_at_twenty(): void
    {
        $subnet = $this->makeSubnet();
        for ($i = 1; $i <= 25; $i++) {
            $this->makeIp($subnet, sprintf('10.95.10.%d', $i));
        }

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.ip-addresses.search', ['q' => '10.95.10.']));

        $response->assertOk();
        $this->assertCount(20, $response->json('results'));
    }

    public function test_search_response_shape_label_and_meta(): void
    {
        $subnet = $this->makeSubnet('Shape Subnet');
        $asset = $this->makeAsset('IP-SEARCH-ASSET');
        $ip = $this->makeIp($subnet, '10.95.11.9', [
            'type' => 'reserved',
            'inventory_asset_id' => $asset->id,
            'last_seen_at' => '2026-10-06 12:00:00',
        ]);

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.ip-addresses.search', ['q' => '10.95.11.9']));

        $response->assertOk();
        $response->assertJsonStructure(['results' => [['id', 'label', 'meta']]]);
        $response->assertJsonPath('results.0.id', $ip->id);
        $response->assertJsonPath('results.0.label', '10.95.11.9 — Shape Subnet');
        $response->assertJsonPath('results.0.meta', 'reserved · Asset IP-SEARCH-ASSET · last seen 2026-10-06 12:00');
    }

    public function test_search_label_includes_the_subnet_vlan(): void
    {
        $vlan = $this->makeVlan('Private IPs', 102);
        $subnet = $this->makeSubnet('Edge Subnet', $vlan);
        $ip = $this->makeIp($subnet, '10.95.29.150');

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.ip-addresses.search', ['q' => '10.95.29.150']));

        $response->assertOk();
        $response->assertJsonPath('results.0.id', $ip->id);
        $response->assertJsonPath('results.0.label', '10.95.29.150 — Edge Subnet · Private IPs (102)');
    }

    public function test_search_label_falls_back_to_vlan_id_when_the_vlan_name_is_blank(): void
    {
        $vlan = $this->makeVlan('', 102);
        $subnet = $this->makeSubnet('Edge Subnet', $vlan);
        $ip = $this->makeIp($subnet, '10.95.30.150');

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.ip-addresses.search', ['q' => '10.95.30.150']));

        $response->assertOk();
        $response->assertJsonPath('results.0.id', $ip->id);
        $response->assertJsonPath('results.0.label', '10.95.30.150 — Edge Subnet · VLAN 102 (102)');
    }

    public function test_search_is_forbidden_without_view_permission(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $adminRole->permissions()->detach(
            Permission::whereIn('name', ['inventory.view', 'inventory.manage'])->pluck('id'),
        );

        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->getJson(route('admin.ip-addresses.search', ['q' => 'anything']))
            ->assertForbidden();
    }

    public function test_create_form_renders_the_picker_block(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.inventory-assets.create'));

        $response->assertOk();
        $response->assertSee('data-ip-picker', false);
        $response->assertSee('data-ip-search-input', false);
        $response->assertSee('name="ip_picker_present"', false);
        $response->assertSee(route('admin.ip-addresses.search'), false);
    }

    public function test_edit_form_pre_renders_chips_for_linked_ips(): void
    {
        $subnet = $this->makeSubnet('Chip Subnet');
        $asset = $this->makeAsset('IP-EDIT-CHIP');
        $ip = $this->makeIp($subnet, '10.95.12.10', ['inventory_asset_id' => $asset->id]);

        $response = $this->actingAsAdmin()->get(route('admin.inventory-assets.edit', $asset));

        $response->assertOk();
        $response->assertSee('data-ip-picker', false);
        $response->assertSee('data-ip-id="'.$ip->id.'"', false);
        $response->assertSee('name="ip_address_ids[]"', false);
        $response->assertSee('value="'.$ip->id.'"', false);
        $response->assertSee('Chip Subnet');
    }

    public function test_edit_form_pre_renders_chips_with_the_subnet_vlan(): void
    {
        $vlan = $this->makeVlan('Private IPs', 102);
        $subnet = $this->makeSubnet('Edge Subnet', $vlan);
        $asset = $this->makeAsset('IP-EDIT-VLAN');
        $ip = $this->makeIp($subnet, '10.95.12.150', ['inventory_asset_id' => $asset->id]);

        $response = $this->actingAsAdmin()->get(route('admin.inventory-assets.edit', $asset));

        $response->assertOk();
        $response->assertSee('data-ip-id="'.$ip->id.'"', false);
        $response->assertSee('10.95.12.150 — Edge Subnet · Private IPs (102)', false);
    }

    public function test_ip_chips_repopulate_after_a_validation_error(): void
    {
        $subnet = $this->makeSubnet('Repop Subnet');
        $asset = $this->makeAsset('IP-REPOP-1');
        $ip = $this->makeIp($subnet, '10.95.13.10');

        $response = $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), [
            'asset_tag' => 'IP-REPOP-1',
            'rack_u_position' => 999, // no rack -> validation error, form bounces back
            'ip_picker_present' => 1,
            'ip_address_ids' => [$ip->id],
        ]);

        $response->assertSessionHasErrors('rack_u_position');

        $this->actingAsAdmin()
            ->get(route('admin.inventory-assets.edit', $asset))
            ->assertOk()
            ->assertSee('data-ip-id="'.$ip->id.'"', false);
    }

    public function test_attach_via_asset_form_sets_polymorphic_assignment_and_writes_history(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-MORPH-1');
        $ip = $this->makeIp($subnet, '10.95.20.10');

        $response = $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), [
            'asset_tag' => 'IP-MORPH-1',
            'ip_picker_present' => '1',
            'ip_address_ids' => [$ip->id],
        ]);

        $response->assertSessionHasNoErrors();

        $fresh = $ip->fresh();
        $this->assertSame('inventory', $fresh->assigned_to_type);
        $this->assertSame($asset->id, $fresh->assigned_to_id);
        $this->assertSame($asset->id, $fresh->inventory_asset_id);
        $this->assertSame('assigned', $fresh->type);
        $this->assertSame('assigned', $fresh->status);

        $history = IpAllocationHistory::where('ip_address_id', $ip->id)->sole();
        $this->assertSame('assigned', $history->action);
        $this->assertNull($history->previous_assigned_to_type);
        $this->assertNull($history->previous_assigned_to_id);
        $this->assertSame('inventory', $history->new_assigned_to_type);
        $this->assertSame($asset->id, $history->new_assigned_to_id);

        $snapshot = json_decode($history->ip_address_snapshot, true);
        $this->assertNull($snapshot['assigned_to_type'], 'snapshot must capture the row before mutation');
    }

    public function test_resaving_the_same_ip_chip_is_idempotent(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-IDEMPOTENT-1');
        $ip = $this->makeIp($subnet, '10.95.21.10');

        $payload = [
            'asset_tag' => 'IP-IDEMPOTENT-1',
            'ip_picker_present' => '1',
            'ip_address_ids' => [$ip->id],
        ];

        $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), $payload)->assertSessionHasNoErrors();
        $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, IpAllocationHistory::where('ip_address_id', $ip->id)->where('action', 'assigned')->count());
        $this->assertSame('inventory', $ip->fresh()->assigned_to_type);
    }

    public function test_detach_restores_available_and_writes_released_history(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-DETACH-MORPH');
        $ip = $this->makeIp($subnet, '10.95.22.10');

        $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), [
            'asset_tag' => 'IP-DETACH-MORPH',
            'ip_picker_present' => '1',
            'ip_address_ids' => [$ip->id],
        ])->assertSessionHasNoErrors();

        $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), [
            'asset_tag' => 'IP-DETACH-MORPH',
            'ip_picker_present' => '1',
        ])->assertSessionHasNoErrors();

        $fresh = $ip->fresh();
        $this->assertNull($fresh->assigned_to_type);
        $this->assertNull($fresh->assigned_to_id);
        $this->assertNull($fresh->inventory_asset_id);
        $this->assertSame('available', $fresh->type);

        $history = IpAllocationHistory::where('ip_address_id', $ip->id)->where('action', 'released')->sole();
        $this->assertSame('inventory', $history->previous_assigned_to_type);
        $this->assertSame($asset->id, $history->previous_assigned_to_id);
        $this->assertNull($history->new_assigned_to_type);

        $snapshot = json_decode($history->ip_address_snapshot, true);
        $this->assertSame('inventory', $snapshot['assigned_to_type']);
    }

    public function test_ip_assigned_to_a_hosting_account_is_rejected_by_the_asset_form(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-REJECT-HOSTING');
        $account = $this->makeAccount();
        $ip = $this->makeIp($subnet, '10.95.23.10', [
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
            'type' => 'assigned',
        ]);

        $response = $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), [
            'asset_tag' => 'IP-REJECT-HOSTING',
            'ip_picker_present' => '1',
            'ip_address_ids' => [$ip->id],
        ]);

        $response->assertSessionHasErrors([
            'ip_address_ids.0' => 'This IP is already assigned to hosting account.',
        ]);
        $this->assertSame($account->id, $ip->fresh()->assigned_to_id);
        $this->assertNull($ip->fresh()->inventory_asset_id);
    }

    public function test_same_asset_chip_is_accepted(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-SAME-ASSET');
        $ip = $this->makeIp($subnet, '10.95.24.10', [
            'inventory_asset_id' => $asset->id,
            'assigned_to_type' => 'inventory',
            'assigned_to_id' => $asset->id,
            'type' => 'assigned',
        ]);

        $response = $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), [
            'asset_tag' => 'IP-SAME-ASSET',
            'ip_picker_present' => '1',
            'ip_address_ids' => [$ip->id],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($asset->id, $ip->fresh()->inventory_asset_id);
        $this->assertSame(0, IpAllocationHistory::where('ip_address_id', $ip->id)->count());
    }

    public function test_available_scope_excludes_an_asset_linked_ip(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-POOL-SAFE');
        $ip = $this->makeIp($subnet, '10.95.25.10');

        $this->actingAsAdmin()->put(route('admin.inventory-assets.update', $asset), [
            'asset_tag' => 'IP-POOL-SAFE',
            'ip_picker_present' => '1',
            'ip_address_ids' => [$ip->id],
        ])->assertSessionHasNoErrors();

        $this->assertFalse(IpAddress::available()->whereKey($ip->id)->exists());
        $this->assertFalse(IpAddress::byStatus('available')->whereKey($ip->id)->exists());
    }

    public function test_search_meta_shows_assigned_and_the_asset_tag(): void
    {
        $subnet = $this->makeSubnet('Meta Subnet');
        $asset = $this->makeAsset('META-ASSET');
        $ip = $this->makeIp($subnet, '10.95.26.9', [
            'inventory_asset_id' => $asset->id,
            'assigned_to_type' => 'inventory',
            'assigned_to_id' => $asset->id,
            'type' => 'assigned',
        ]);

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.ip-addresses.search', ['q' => '10.95.26.9']));

        $response->assertOk();
        $response->assertJsonPath('results.0.id', $ip->id);
        $response->assertJsonPath('results.0.meta', 'assigned · Asset META-ASSET');
    }

    public function test_migration_normalizes_inventory_asset_id_only_rows(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-MIGRATE-FWD');
        $ip = $this->makeIp($subnet, '10.95.27.10', [
            'inventory_asset_id' => $asset->id,
            'type' => 'available',
        ]);

        $this->runInventoryAssetIpMigration();

        $fresh = $ip->fresh();
        $this->assertSame('inventory', $fresh->assigned_to_type);
        $this->assertSame($asset->id, $fresh->assigned_to_id);
        $this->assertSame('assigned', $fresh->type);
    }

    public function test_migration_fills_inventory_asset_id_from_the_polymorphic_pair(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('IP-MIGRATE-REV');
        $ip = $this->makeIp($subnet, '10.95.28.10', [
            'assigned_to_type' => 'inventory',
            'assigned_to_id' => $asset->id,
            'type' => 'assigned',
        ]);

        $this->runInventoryAssetIpMigration();

        $this->assertSame($asset->id, $ip->fresh()->inventory_asset_id);
    }

    public function test_view_only_mode_hides_every_action_option(): void
    {
        $subnet = $this->makeSubnet('View Subnet');
        $asset = $this->makeAsset('IP-VIEWONLY-1');
        $this->makeIp($subnet, '10.95.29.10', ['inventory_asset_id' => $asset->id]);

        // A non-admin role: the admin role passes every @can through AdminLTE's
        // superuser shortcut, which would mask a view-only assertion.
        $viewerRole = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $view = Permission::firstOrCreate(['name' => 'inventory.view'], ['label' => 'View Inventory']);
        $viewerRole->permissions()->syncWithoutDetaching([$view->id]);

        $user = User::factory()->create();
        $user->assignRole('viewer');

        $this->actingAs($user)
            ->get(route('admin.inventory-assets.show', $asset))
            ->assertOk()
            ->assertDontSee(route('admin.inventory-assets.edit', $asset), false)
            ->assertDontSee('Unlink IP')
            ->assertDontSee('<th class="text-end">Actions</th>', false);
    }
}
