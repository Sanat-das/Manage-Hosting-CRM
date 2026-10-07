<?php

namespace Tests\Feature;

use App\Models\AssetRelationship;
use App\Models\DevicePort;
use App\Models\InventoryAsset;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Permission;
use App\Models\PortConnection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting an inventory asset must release everything pointing at it: linked
 * IPs return to the pool (with history), cables are disconnected (with their
 * ledger event), and the relationship graph drops every edge on either side.
 * The admin panel and the API share InventoryAssetDeletionService, so both
 * paths are covered here.
 */
class InventoryAssetDeletionTest extends TestCase
{
    use RefreshDatabase;

    private int $subnetSequence = 0;

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

    private function apiUser(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole('admin');

        return $user;
    }

    private function actingAsApi(User $user): self
    {
        $token = $user->createToken('test-token')->plainTextToken;

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(string $assetTag, array $overrides = []): InventoryAsset
    {
        return InventoryAsset::create(array_merge([
            'asset_tag' => $assetTag,
            'asset_type' => 'switch',
        ], $overrides));
    }

    private function makeSubnet(string $name = 'Deletion Subnet'): IpSubnet
    {
        $this->subnetSequence++;

        return IpSubnet::create([
            'name' => $name,
            'subnet_cidr' => "10.96.{$this->subnetSequence}.0/24",
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePort(InventoryAsset $asset, array $overrides = []): DevicePort
    {
        return $asset->ports()->create(array_merge([
            'name' => 'GigabitEthernet1/0/1',
            'port_type' => 'ethernet',
        ], $overrides));
    }

    private function connect(DevicePort $first, DevicePort $second): PortConnection
    {
        return PortConnection::create([
            'port_a_id' => min($first->id, $second->id),
            'port_b_id' => max($first->id, $second->id),
            'cable_label' => 'C-1',
        ]);
    }

    /**
     * Link the asset to a peer, a parent and a child through the canonical
     * relationship graph so the delete has edges on both sides to clear.
     *
     * @return array{child: AssetRelationship, parent: AssetRelationship}
     */
    private function linkRelationships(InventoryAsset $asset): array
    {
        $child = $this->makeAsset($asset->asset_tag.'-CHILD');
        $parent = $this->makeAsset($asset->asset_tag.'-PARENT');

        $asParent = AssetRelationship::create([
            'parent_kind' => 'inventory_asset',
            'parent_id' => $asset->id,
            'child_kind' => 'inventory_asset',
            'child_id' => $child->id,
            'relationship_type' => 'contains',
        ]);

        $asChild = AssetRelationship::create([
            'parent_kind' => 'inventory_asset',
            'parent_id' => $parent->id,
            'child_kind' => 'inventory_asset',
            'child_id' => $asset->id,
            'relationship_type' => 'contains',
        ]);

        return ['child' => $asParent, 'parent' => $asChild];
    }

    public function test_admin_delete_releases_ips_disconnects_cables_and_clears_relationships(): void
    {
        $asset = $this->makeAsset('DEL-ADMIN');
        $peer = $this->makeAsset('DEL-ADMIN-PEER');

        $subnet = $this->makeSubnet();
        $ip = $this->makeIp($subnet, '10.96.1.10', [
            'inventory_asset_id' => $asset->id,
            'assigned_to_type' => 'inventory',
            'assigned_to_id' => $asset->id,
            'type' => 'assigned',
        ]);

        $portA = $this->makePort($asset, ['name' => 'Gi1/0/1']);
        $portB = $this->makePort($peer, ['name' => 'Gi1/0/2']);
        $connection = $this->connect($portA, $portB);

        $this->linkRelationships($asset);

        $response = $this->actingAsAdmin()->delete("/admin/inventory-assets/{$asset->id}");

        $response->assertRedirect(route('admin.inventory-assets.index'));
        $this->assertSoftDeleted('inventory_assets', ['id' => $asset->id]);

        $freshIp = $ip->fresh();
        $this->assertNull($freshIp->inventory_asset_id);
        $this->assertNull($freshIp->assigned_to_type);
        $this->assertNull($freshIp->assigned_to_id);
        $this->assertSame('available', $freshIp->type);
        $this->assertDatabaseHas('ip_allocation_history', [
            'ip_address_id' => $ip->id,
            'action' => 'released',
        ]);

        $this->assertDatabaseMissing('port_connections', ['id' => $connection->id]);
        $this->assertDatabaseHas('port_connection_events', [
            'port_connection_id' => $connection->id,
            'action' => 'disconnected',
        ]);

        $this->assertDatabaseCount('asset_relationships', 0);

        // The asset's own ports survive the teardown.
        $this->assertDatabaseHas('device_ports', ['id' => $portA->id]);
        $this->assertDatabaseHas('device_ports', ['id' => $portB->id]);
    }

    public function test_api_delete_releases_ips_disconnects_cables_and_clears_relationships(): void
    {
        $asset = $this->makeAsset('DEL-API');
        $peer = $this->makeAsset('DEL-API-PEER');

        $subnet = $this->makeSubnet();
        $ip = $this->makeIp($subnet, '10.96.2.10', [
            'inventory_asset_id' => $asset->id,
            'assigned_to_type' => 'inventory',
            'assigned_to_id' => $asset->id,
            'type' => 'assigned',
        ]);

        $portA = $this->makePort($asset, ['name' => 'Gi2/0/1']);
        $portB = $this->makePort($peer, ['name' => 'Gi2/0/2']);
        $connection = $this->connect($portA, $portB);

        $this->linkRelationships($asset);

        $response = $this->actingAsApi($this->apiUser())->deleteJson("/api/inventory-assets/{$asset->id}");

        $response->assertOk();
        $response->assertJsonPath('data', null);
        $response->assertJsonPath('message', 'Inventory asset deleted.');

        $this->assertSoftDeleted('inventory_assets', ['id' => $asset->id]);

        $freshIp = $ip->fresh();
        $this->assertNull($freshIp->inventory_asset_id);
        $this->assertSame('available', $freshIp->type);
        $this->assertDatabaseHas('ip_allocation_history', [
            'ip_address_id' => $ip->id,
            'action' => 'released',
        ]);

        $this->assertDatabaseMissing('port_connections', ['id' => $connection->id]);
        $this->assertDatabaseHas('port_connection_events', [
            'port_connection_id' => $connection->id,
            'action' => 'disconnected',
        ]);

        $this->assertDatabaseCount('asset_relationships', 0);
    }
}
