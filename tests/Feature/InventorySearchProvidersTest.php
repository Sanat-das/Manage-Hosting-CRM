<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\InventoryAsset;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Rack;
use App\Models\User;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\Providers\InventoryAssetSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The inventory-assets provider group.
 *
 * Mirrors the sibling hosting/billing provider suites: each test drives
 * `GlobalSearchService::groups()` with an explicit one-provider registry, so a
 * presence or absence assertion cannot be masked by an unrelated provider. The
 * gate is the real seeded `inventory.view` permission — the same one the
 * inventory routes carry (`routes/admin/inventory.php`).
 */
class InventorySearchProvidersTest extends TestCase
{
    use CreatesChatUsers {
        chatUser as panelUserWith;
    }
    use RefreshDatabase;

    // --- identifier lookup + exact URL --------------------------------------

    public function test_an_inventory_asset_is_found_by_its_asset_tag_and_links_to_its_show_page(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-ACME-0001']);

        $user = $this->panelUserWith('inventory.view');
        $groups = $this->groupsFor($user, 'AST-ACME-0001');

        $group = $groups['inventory-assets'] ?? null;

        $this->assertNotNull($group);
        $this->assertSame('Inventory', $group['label']);
        $this->assertCount(1, $group['results']);

        $result = $group['results'][0];

        $this->assertSame($asset->id, $result['id']);
        $this->assertSame('AST-ACME-0001', $result['label']);
        $this->assertSame(route('admin.inventory-assets.show', $asset), $result['url']);
    }

    public function test_an_inventory_asset_is_found_by_its_serial_number(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-ACME-0002', 'serial_number' => 'SN-ACME-9001']);

        $user = $this->panelUserWith('inventory.view');
        $groups = $this->groupsFor($user, 'SN-ACME-9001');

        $group = $groups['inventory-assets'] ?? null;

        $this->assertNotNull($group);
        $this->assertCount(1, $group['results']);
        $this->assertSame($asset->id, $group['results'][0]['id']);
        $this->assertSame('AST-ACME-0002', $group['results'][0]['label']);
    }

    /**
     * The provider mirrors the standalone picker: a term may match a datacenter
     * or rack name through the asset's own belongsTo relations.
     */
    public function test_an_inventory_asset_is_found_by_its_datacenter_and_rack_names(): void
    {
        $datacenter = Datacenter::create(['name' => 'Northwind DC', 'code' => 'NW1', 'status' => 'active']);
        $rack = Rack::create(['datacenter_id' => $datacenter->id, 'name' => 'Rockwood Rack', 'u_height' => 42, 'status' => 'active']);
        $asset = $this->makeAsset([
            'asset_tag' => 'AST-ACME-0003',
            'datacenter_id' => $datacenter->id,
            'rack_id' => $rack->id,
        ]);

        $user = $this->panelUserWith('inventory.view');

        $byDatacenter = $this->groupsFor($user, 'Northwind');

        $this->assertArrayHasKey('inventory-assets', $byDatacenter);
        $this->assertSame($asset->id, $byDatacenter['inventory-assets']['results'][0]['id']);

        $byRack = $this->groupsFor($user, 'Rockwood');

        $this->assertArrayHasKey('inventory-assets', $byRack);
        $this->assertSame($asset->id, $byRack['inventory-assets']['results'][0]['id']);
    }

    /**
     * An asset is found by an IP address assigned to it: the provider matches
     * the asset's own `ipAddresses` relation, so a maintainer holding the IP
     * from a server's network config still lands on the asset.
     */
    public function test_an_inventory_asset_is_found_by_an_assigned_ip_address(): void
    {
        $subnet = IpSubnet::create(['name' => 'Edge Subnet', 'subnet_cidr' => '10.95.20.0/24']);
        $asset = $this->makeAsset(['asset_tag' => 'AST-IP-0001']);
        IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => '10.95.20.14',
            'inventory_asset_id' => $asset->id,
        ]);

        $user = $this->panelUserWith('inventory.view');
        $groups = $this->groupsFor($user, '10.95.20.14');

        $group = $groups['inventory-assets'] ?? null;

        $this->assertNotNull($group);
        $this->assertCount(1, $group['results']);
        $this->assertSame($asset->id, $group['results'][0]['id']);
        $this->assertSame('AST-IP-0001', $group['results'][0]['label']);
    }

    // --- permission gating --------------------------------------------------

    public function test_the_inventory_group_is_absent_for_a_viewer_without_inventory_view(): void
    {
        $this->makeAsset(['asset_tag' => 'AST-ACME-0004']);

        $user = $this->panelUserWith('products.view');
        $groups = $this->groupsFor($user, 'AST-ACME');

        $this->assertArrayNotHasKey('inventory-assets', $groups);
        $this->assertSame([], $groups);
    }

    // --- seeded role composition --------------------------------------------

    /**
     * The end-to-end composition the per-leg suites leave uncovered: a user
     * holding the real seeded `support` role sees the group through
     * `GlobalSearchService`, and the seeded `staff` role — which deliberately
     * omits `inventory.view` — does not.
     *
     * Both users name their role through the `users.role` column, exactly as
     * `PanelAccessTest` builds panel users, so the group gate is resolved from
     * the seeded role's own permissions rather than a directly granted one.
     */
    public function test_the_seeded_support_role_sees_the_inventory_group_while_staff_does_not(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-ROLE-0001']);

        $support = User::factory()->create(['role' => 'support']);
        $supportGroups = $this->groupsFor($support, 'AST-ROLE-0001');

        $this->assertArrayHasKey('inventory-assets', $supportGroups);
        $this->assertSame($asset->id, $supportGroups['inventory-assets']['results'][0]['id']);

        $staff = User::factory()->create(['role' => 'staff']);
        $staffGroups = $this->groupsFor($staff, 'AST-ROLE-0001');

        $this->assertArrayNotHasKey('inventory-assets', $staffGroups);
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function groupsFor(User $user, string $term, int $limit = 5): array
    {
        $service = new GlobalSearchService([InventoryAssetSearchProvider::class]);

        return collect($service->groups($service->permissionNames($user), $term, $limit))
            ->keyBy('key')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(array $overrides = []): InventoryAsset
    {
        return InventoryAsset::create(array_merge([
            'asset_tag' => 'AST-DEFAULT-1',
            'asset_type' => 'server',
        ], $overrides));
    }
}
