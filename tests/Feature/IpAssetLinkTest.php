<?php

namespace Tests\Feature;

use App\Models\HostingAccount;
use App\Models\InventoryAsset;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpAssetLinkTest extends TestCase
{
    use RefreshDatabase;

    private int $subnetSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role and IPAM permissions in the test DB.
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'ip-addresses.view'], ['label' => 'View IP Addresses']);
        $manage = Permission::firstOrCreate(['name' => 'ip-addresses.manage'], ['label' => 'Manage IP Addresses']);
        $inventoryView = Permission::firstOrCreate(['name' => 'inventory.view'], ['label' => 'View Inventory']);
        $inventoryManage = Permission::firstOrCreate(['name' => 'inventory.manage'], ['label' => 'Manage Inventory']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id, $inventoryView->id, $inventoryManage->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeSubnet(?Vlan $vlan = null): IpSubnet
    {
        $this->subnetSequence++;

        return IpSubnet::create([
            'name' => "Link Subnet {$this->subnetSequence}",
            'subnet_cidr' => "10.90.{$this->subnetSequence}.0/24",
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

    private function makeAsset(string $assetTag): InventoryAsset
    {
        return InventoryAsset::create([
            'asset_tag' => $assetTag,
            'asset_type' => 'server',
        ]);
    }

    private function makeAccount(): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        return HostingAccount::create([
            'customer_id' => $sequence,
            'product_id' => $sequence,
            'username' => "acct{$sequence}",
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeIp(IpSubnet $subnet, string $address, array $overrides = []): IpAddress
    {
        return IpAddress::create(array_merge([
            'subnet_id' => $subnet->id,
            'ip_address' => $address,
        ], $overrides));
    }

    public function test_store_persists_inventory_asset_link(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('LINK-CREATE-1');

        $response = $this->actingAsAdmin()->post('/admin/ip-addresses', [
            'subnet_id' => $subnet->id,
            'ip_address' => '10.90.1.10',
            'inventory_asset_id' => $asset->id,
        ]);

        $response->assertRedirect(route('admin.ip-addresses.index'));
        $this->assertDatabaseHas('ip_addresses', [
            'ip_address' => '10.90.1.10',
            'inventory_asset_id' => $asset->id,
        ]);
    }

    public function test_store_rejects_unknown_inventory_asset(): void
    {
        $subnet = $this->makeSubnet();

        $response = $this->actingAsAdmin()->post('/admin/ip-addresses', [
            'subnet_id' => $subnet->id,
            'ip_address' => '10.90.1.11',
            'inventory_asset_id' => 999999,
        ]);

        $response->assertSessionHasErrors('inventory_asset_id');
        $this->assertDatabaseMissing('ip_addresses', ['ip_address' => '10.90.1.11']);
    }

    public function test_update_links_then_unlinks_inventory_asset(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('LINK-UPDATE-1');
        $ip = IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => '10.90.2.10',
        ]);

        $link = $this->actingAsAdmin()->put("/admin/ip-addresses/{$ip->id}", [
            'inventory_asset_id' => $asset->id,
        ]);

        $link->assertRedirect(route('admin.ip-addresses.show', $ip));
        $this->assertSame($asset->id, $ip->fresh()->inventory_asset_id);

        $unlink = $this->actingAsAdmin()->put("/admin/ip-addresses/{$ip->id}", [
            'inventory_asset_id' => null,
        ]);

        $unlink->assertRedirect(route('admin.ip-addresses.show', $ip));
        $this->assertNull($ip->fresh()->inventory_asset_id);
    }

    public function test_show_links_to_linked_inventory_asset(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('LINK-SHOW-1');
        $ip = IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => '10.90.3.10',
            'inventory_asset_id' => $asset->id,
        ]);

        $response = $this->actingAsAdmin()->get("/admin/ip-addresses/{$ip->id}");

        $response->assertSee(route('admin.inventory-assets.show', $asset), false);
        $response->assertSee($asset->asset_tag, false);
    }

    public function test_show_page_renders_linked_ips_in_the_ip_addresses_card_only(): void
    {
        $vlan = $this->makeVlan('Production', 202);
        $subnet = $this->makeSubnet($vlan);
        $asset = $this->makeAsset('LINK-DETAILS-1');
        $ip = $this->makeIp($subnet, '10.90.9.10', [
            'inventory_asset_id' => $asset->id,
            'last_seen_at' => '2026-10-06 12:34:00',
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.inventory-assets.show', $asset));

        $response->assertOk();
        $response->assertSee(route('admin.ip-addresses.show', $ip), false);
        $response->assertSeeInOrder(['<th>Subnet</th>', '<th>VLAN</th>', '<th>Last Seen</th>'], false);
        $response->assertSeeInOrder([$subnet->name, 'Production (202)', '2026-10-06 12:34'], false);
        $response->assertSee('aria-label="Unlink IP"', false);

        // The linked-IP rows no longer live in the Details card.
        $response->assertDontSee('IP Address(es)');
        // The removed Details row markup is gone; the sidebar still links "VLANs".
        $response->assertDontSee('<th class="text-muted">VLANs</th>', false);
    }

    public function test_show_page_omits_the_ip_rows_without_linked_ips(): void
    {
        $asset = $this->makeAsset('LINK-DETAILS-EMPTY');

        $response = $this->actingAsAdmin()->get(route('admin.inventory-assets.show', $asset));

        $response->assertOk();
        $response->assertDontSee('IP Address(es)');
        // The removed Details row markup is gone; the sidebar still links "VLANs".
        $response->assertDontSee('<th class="text-muted">VLANs</th>', false);
        // The remaining Details rows are untouched.
        $response->assertSeeInOrder(['U Position', 'Purchase Date'], false);
    }

    public function test_ip_addresses_card_vlan_cell_is_an_em_dash_without_a_vlan(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('LINK-IPCARD-NOVLAN');
        $ip = $this->makeIp($subnet, '10.90.9.11', ['inventory_asset_id' => $asset->id]);

        $response = $this->actingAsAdmin()->get(route('admin.inventory-assets.show', $asset));

        $response->assertOk();
        $response->assertSeeInOrder(['<th>Subnet</th>', '<th>VLAN</th>', '<th>Last Seen</th>'], false);
        $response->assertSee('10.90.9.11');
        // Subnet name, then the em-dash VLAN cell, then the never-seen cell.
        $response->assertSeeInOrder([$subnet->name, '—', 'never'], false);
    }

    public function test_store_links_the_polymorphic_assignment(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('LINK-MORPH-STORE');

        $response = $this->actingAsAdmin()->post('/admin/ip-addresses', [
            'subnet_id' => $subnet->id,
            'ip_address' => '10.90.8.10',
            'inventory_asset_id' => $asset->id,
        ]);

        $response->assertRedirect(route('admin.ip-addresses.index'));

        $ip = IpAddress::where('ip_address', '10.90.8.10')->firstOrFail();
        $this->assertSame('inventory', $ip->assigned_to_type);
        $this->assertSame($asset->id, $ip->assigned_to_id);
        $this->assertSame($asset->id, $ip->inventory_asset_id);
        $this->assertSame('assigned', $ip->type);
    }

    public function test_update_links_the_polymorphic_assignment(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('LINK-MORPH-1');
        $ip = $this->makeIp($subnet, '10.90.4.10');

        $response = $this->actingAsAdmin()->put("/admin/ip-addresses/{$ip->id}", [
            'inventory_asset_id' => $asset->id,
        ]);

        $response->assertRedirect(route('admin.ip-addresses.show', $ip));

        $fresh = $ip->fresh();
        $this->assertSame('inventory', $fresh->assigned_to_type);
        $this->assertSame($asset->id, $fresh->assigned_to_id);
        $this->assertSame($asset->id, $fresh->inventory_asset_id);
        $this->assertSame('assigned', $fresh->type);
    }

    public function test_clearing_inventory_asset_id_releases_the_assignment(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('LINK-MORPH-CLEAR');
        $ip = $this->makeIp($subnet, '10.90.5.10', [
            'inventory_asset_id' => $asset->id,
            'assigned_to_type' => 'inventory',
            'assigned_to_id' => $asset->id,
            'type' => 'assigned',
        ]);

        $response = $this->actingAsAdmin()->put("/admin/ip-addresses/{$ip->id}", [
            'inventory_asset_id' => null,
        ]);

        $response->assertRedirect(route('admin.ip-addresses.show', $ip));

        $fresh = $ip->fresh();
        $this->assertNull($fresh->assigned_to_type);
        $this->assertNull($fresh->assigned_to_id);
        $this->assertNull($fresh->inventory_asset_id);
        $this->assertSame('available', $fresh->type);

        $this->assertDatabaseHas('ip_allocation_history', [
            'ip_address_id' => $ip->id,
            'action' => 'released',
            'previous_assigned_to_type' => 'inventory',
            'previous_assigned_to_id' => $asset->id,
        ]);
    }

    public function test_release_action_clears_inventory_asset_id(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('LINK-MORPH-RELEASE');
        $ip = $this->makeIp($subnet, '10.90.6.10', [
            'inventory_asset_id' => $asset->id,
            'assigned_to_type' => 'inventory',
            'assigned_to_id' => $asset->id,
            'type' => 'assigned',
        ]);

        $response = $this->actingAsAdmin()->post(route('admin.ip-addresses.release', $ip));

        $response->assertRedirect(route('admin.ip-addresses.show', $ip));

        $fresh = $ip->fresh();
        $this->assertNull($fresh->assigned_to_type);
        $this->assertNull($fresh->assigned_to_id);
        $this->assertNull($fresh->inventory_asset_id);
        $this->assertSame('available', $fresh->type);
    }

    public function test_assign_action_leases_an_available_ip_to_a_hosting_account(): void
    {
        $subnet = $this->makeSubnet();
        $ip = $this->makeIp($subnet, '10.90.10.10');
        $account = $this->makeAccount();

        $response = $this->actingAsAdmin()->post(route('admin.ip-addresses.assign', $ip), [
            'hosting_account_id' => $account->id,
        ]);

        $response->assertRedirect(route('admin.ip-addresses.show', $ip));
        $response->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'assigned'));

        $fresh = $ip->fresh();
        $this->assertSame('assigned', $fresh->type);
        $this->assertSame(HostingAccount::class, $fresh->assigned_to_type);
        $this->assertSame($account->id, $fresh->assigned_to_id);

        $this->assertDatabaseHas('ip_addresses', [
            'id' => $ip->id,
            'type' => 'assigned',
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);
        $this->assertDatabaseHas('ip_allocation_history', [
            'ip_address_id' => $ip->id,
            'action' => 'assigned',
            'new_assigned_to_type' => HostingAccount::class,
            'new_assigned_to_id' => $account->id,
        ]);
    }

    public function test_index_and_show_render_the_inventory_asset_as_the_assignee(): void
    {
        $subnet = $this->makeSubnet();
        $asset = $this->makeAsset('LINK-RENDER-1');
        $ip = $this->makeIp($subnet, '10.90.7.10', [
            'inventory_asset_id' => $asset->id,
            'assigned_to_type' => 'inventory',
            'assigned_to_id' => $asset->id,
            'type' => 'assigned',
        ]);

        $assetUrl = route('admin.inventory-assets.show', $asset);

        $this->actingAsAdmin()->get("/admin/ip-addresses/{$ip->id}")
            ->assertOk()
            ->assertSee('Inventory Asset')
            ->assertSee($assetUrl, false)
            ->assertSee($asset->asset_tag, false);

        $this->actingAsAdmin()->get('/admin/ip-addresses')
            ->assertOk()
            ->assertSee($assetUrl, false)
            ->assertSee($asset->asset_tag, false);
    }
}
