<?php

namespace Tests\Feature;

use App\Models\DevicePort;
use App\Models\InventoryAsset;
use App\Models\Permission;
use App\Models\PortConnection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice B1: manual device port CRUD on the inventory asset show page, the
 * ports.manage permission gate, and the peer-port picker search contract.
 */
class DevicePortCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $permissions = [
            Permission::firstOrCreate(['name' => 'inventory.view'], ['label' => 'View Inventory']),
            Permission::firstOrCreate(['name' => 'inventory.manage'], ['label' => 'Manage Inventory']),
            Permission::firstOrCreate(['name' => 'ports.view'], ['label' => 'View Ports & Connections']),
            Permission::firstOrCreate(['name' => 'ports.manage'], ['label' => 'Manage Ports & Connections']),
        ];
        $adminRole->permissions()->syncWithoutDetaching(collect($permissions)->pluck('id')->all());

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
            'asset_type' => 'switch',
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

    public function test_create_port_normalizes_the_mac_and_defaults_source(): void
    {
        $asset = $this->makeAsset('PORT-SW-1');

        $response = $this->actingAsAdmin()->post(route('admin.inventory-assets.ports.store', $asset), [
            'name' => 'Gi1/0/24',
            'port_number' => '24',
            'port_type' => 'ethernet',
            'media' => 'copper',
            'speed_bps' => '1000000000',
            'status' => 'up',
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'notes' => 'Core uplink',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('device_ports', [
            'inventory_asset_id' => $asset->id,
            'name' => 'Gi1/0/24',
            'port_number' => '24',
            'port_type' => 'ethernet',
            'media' => 'copper',
            'speed_bps' => 1000000000,
            'status' => 'up',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'source' => 'manual',
            'notes' => 'Core uplink',
        ]);
    }

    public function test_update_port_leaves_source_and_snmp_columns_untouched(): void
    {
        $asset = $this->makeAsset('PORT-SW-2');
        $port = $this->makePort($asset, [
            'name' => 'Gi1/0/1',
            'source' => 'snmp',
            'snmp_if_index' => 7,
            'snmp_if_descr' => 'eth0',
        ]);

        $response = $this->actingAsAdmin()->put(route('admin.inventory-assets.ports.update', [$asset, $port]), [
            'name' => 'Gi1/0/2',
            'port_type' => 'sfp',
            'status' => 'down',
        ]);

        $response->assertSessionHasNoErrors();

        $fresh = $port->fresh();
        $this->assertSame('Gi1/0/2', $fresh->name);
        $this->assertSame('sfp', $fresh->port_type);
        $this->assertSame('down', $fresh->status);
        $this->assertSame('snmp', $fresh->source);
        $this->assertSame(7, $fresh->snmp_if_index);
        $this->assertSame('eth0', $fresh->snmp_if_descr);
    }

    public function test_delete_port_removes_the_row(): void
    {
        $asset = $this->makeAsset('PORT-SW-3');
        $port = $this->makePort($asset);

        $response = $this->actingAsAdmin()
            ->delete(route('admin.inventory-assets.ports.destroy', [$asset, $port]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('device_ports', ['id' => $port->id]);
    }

    public function test_port_name_is_unique_per_asset_but_reusable_across_assets(): void
    {
        $asset = $this->makeAsset('PORT-UNIQ-A');
        $other = $this->makeAsset('PORT-UNIQ-B');
        $this->makePort($asset, ['name' => 'Gi1/0/1']);

        $response = $this->actingAsAdmin()->post(route('admin.inventory-assets.ports.store', $asset), [
            'name' => 'Gi1/0/1',
            'port_type' => 'ethernet',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, DevicePort::where('inventory_asset_id', $asset->id)->count());

        $this->actingAsAdmin()->post(route('admin.inventory-assets.ports.store', $other), [
            'name' => 'Gi1/0/1',
            'port_type' => 'ethernet',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, DevicePort::where('inventory_asset_id', $other->id)->count());
    }

    public function test_update_and_destroy_return_404_for_a_port_of_another_asset(): void
    {
        $asset = $this->makeAsset('PORT-404-A');
        $other = $this->makeAsset('PORT-404-B');
        $port = $this->makePort($other);

        $this->actingAsAdmin()
            ->put(route('admin.inventory-assets.ports.update', [$asset, $port]), [
                'name' => 'Hijacked',
                'port_type' => 'ethernet',
            ])
            ->assertNotFound();

        $this->actingAsAdmin()
            ->delete(route('admin.inventory-assets.ports.destroy', [$asset, $port]))
            ->assertNotFound();

        $this->assertDatabaseHas('device_ports', ['id' => $port->id, 'name' => 'GigabitEthernet1/0/1']);
    }

    public function test_view_only_user_sees_the_ports_table_without_actions(): void
    {
        $asset = $this->makeAsset('PORT-VIEW-1');
        $this->makePort($asset, ['name' => 'Gi1/0/1']);

        $viewerRole = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $viewerRole->permissions()->syncWithoutDetaching([
            Permission::firstOrCreate(['name' => 'inventory.view'], ['label' => 'View Inventory'])->id,
            Permission::firstOrCreate(['name' => 'ports.view'], ['label' => 'View Ports & Connections'])->id,
        ]);

        $user = User::factory()->create();
        $user->assignRole('viewer');

        $response = $this->actingAs($user)->get(route('admin.inventory-assets.show', $asset));

        $response->assertOk();
        $response->assertSee('Gi1/0/1');
        $response->assertDontSee('Add Port');
        $response->assertDontSee('Edit port');
        $response->assertDontSee('Delete port');
        $response->assertDontSee('<th class="text-end">Actions</th>', false);
    }

    public function test_admin_sees_the_port_actions(): void
    {
        $asset = $this->makeAsset('PORT-ADMIN-1');
        $this->makePort($asset, ['name' => 'Gi1/0/1']);

        $response = $this->actingAsAdmin()->get(route('admin.inventory-assets.show', $asset));

        $response->assertOk();
        $response->assertSee('Add Port');
        $response->assertSee('Edit port');
        $response->assertSee('Delete port');
    }

    public function test_ports_manage_implies_ports_view_for_the_picker_endpoint(): void
    {
        $staffRole = Role::firstOrCreate(['name' => 'staff'], ['label' => 'Staff']);
        $staffRole->permissions()->syncWithoutDetaching([
            Permission::firstOrCreate(['name' => 'ports.manage'], ['label' => 'Manage Ports & Connections'])->id,
        ]);

        $user = User::factory()->create();
        $user->assignRole('staff');

        $asset = $this->makeAsset('PORT-IMPLIES-1');
        $port = $this->makePort($asset, ['name' => 'Gi1/0/1']);

        $this->actingAs($user)
            ->getJson(route('admin.ports.search', ['q' => 'Gi1/0/1']))
            ->assertOk()
            ->assertJsonPath('results.0.id', $port->id);
    }

    public function test_search_blank_query_returns_no_results(): void
    {
        $asset = $this->makeAsset('PORT-SEARCH-BLANK');
        $this->makePort($asset, ['name' => 'Gi1/0/1']);

        $this->actingAsAdmin()
            ->getJson(route('admin.ports.search'))
            ->assertOk()
            ->assertJsonPath('results', []);

        $this->actingAsAdmin()
            ->getJson(route('admin.ports.search', ['q' => '   ']))
            ->assertOk()
            ->assertJsonPath('results', []);
    }

    public function test_search_response_shape_label_and_meta(): void
    {
        $asset = $this->makeAsset('SW-CORE-01');
        $port = $this->makePort($asset, [
            'name' => 'GigabitEthernet1/0/24',
            'port_type' => 'ethernet',
            'status' => 'up',
            'mac_address' => '00:15:5D:03:01:A4',
        ]);

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.ports.search', ['q' => 'GigabitEthernet1/0/24']));

        $response->assertOk();
        $response->assertJsonStructure(['results' => [['id', 'label', 'meta', 'disabled']]]);
        $response->assertJsonPath('results.0.id', $port->id);
        $response->assertJsonPath('results.0.label', 'SW-CORE-01 · GigabitEthernet1/0/24');
        $response->assertJsonPath('results.0.meta', 'ethernet · up · 00:15:5D:03:01:A4');
        $response->assertJsonPath('results.0.disabled', false);
    }

    public function test_search_excludes_connected_ports_by_default_and_includes_them_on_request(): void
    {
        $assetA = $this->makeAsset('PORT-CONN-A');
        $assetB = $this->makeAsset('PORT-CONN-B');
        $free = $this->makePort($assetA, ['name' => 'FREE-1']);
        $peer = $this->makePort($assetA, ['name' => 'PEER-1']);
        $connected = $this->makePort($assetB, ['name' => 'CONNECTED-1']);
        $this->connect($connected, $peer);

        $defaultIds = collect(
            $this->actingAsAdmin()->getJson(route('admin.ports.search', ['q' => 'PORT-CONN']))->json('results'),
        )->pluck('id');

        $this->assertTrue($defaultIds->contains($free->id));
        $this->assertFalse($defaultIds->contains($peer->id));
        $this->assertFalse($defaultIds->contains($connected->id));

        $includedIds = collect(
            $this->actingAsAdmin()
                ->getJson(route('admin.ports.search', ['q' => 'PORT-CONN', 'include_connected' => 1]))
                ->json('results'),
        )->pluck('id');

        $this->assertTrue($includedIds->contains($peer->id));
        $this->assertTrue($includedIds->contains($connected->id));
    }

    public function test_search_include_connected_marks_connected_ports_disabled_with_the_peer_annotation(): void
    {
        $asset = $this->makeAsset('SW-CORE-01');
        $free = $this->makePort($asset, [
            'name' => 'Gi1/0/24',
            'port_type' => 'ethernet',
            'status' => 'up',
            'mac_address' => '00:15:5D:03:01:A4',
        ]);
        $connected = $this->makePort($asset, [
            'name' => 'Gi1/0/12',
            'port_type' => 'ethernet',
            'status' => 'up',
        ]);
        $peerAsset = $this->makeAsset('SW-ACCESS-02');
        $peer = $this->makePort($peerAsset, ['name' => 'Gi1/0/4']);
        $this->connect($connected, $peer);

        $results = collect(
            $this->actingAsAdmin()
                ->getJson(route('admin.ports.search', ['q' => 'SW-CORE-01', 'include_connected' => 1]))
                ->assertOk()
                ->json('results'),
        )->keyBy('id');

        $this->assertFalse($results[$free->id]['disabled']);
        $this->assertTrue($results[$connected->id]['disabled']);
        $this->assertStringContainsString(
            'connected to SW-ACCESS-02 Gi1/0/4',
            $results[$connected->id]['meta'],
        );
    }

    public function test_search_include_connected_orders_free_ports_before_connected_ones(): void
    {
        $asset = $this->makeAsset('PORT-ORDER');
        $free = $this->makePort($asset, ['name' => 'Zeta-free']);
        $connected = $this->makePort($asset, ['name' => 'Alpha-conn']);
        $peerAsset = $this->makeAsset('PEER-ASSET-1');
        $peer = $this->makePort($peerAsset, ['name' => 'Peer-1']);
        $this->connect($connected, $peer);

        $results = $this->actingAsAdmin()
            ->getJson(route('admin.ports.search', ['q' => 'ORDER', 'include_connected' => 1]))
            ->assertOk()
            ->json('results');

        // Name order alone would put Alpha-conn first; free-first must win.
        $this->assertSame($free->id, $results[0]['id']);
        $this->assertFalse($results[0]['disabled']);
        $this->assertSame($connected->id, $results[1]['id']);
        $this->assertTrue($results[1]['disabled']);

        $flags = array_column($results, 'disabled');
        $firstDisabled = array_search(true, $flags, true);
        if ($firstDisabled !== false) {
            $this->assertNotContains(false, array_slice($flags, $firstDisabled));
        }
    }

    public function test_search_excludes_the_source_port_and_its_asset(): void
    {
        $assetA = $this->makeAsset('PORT-SOURCE-A');
        $assetB = $this->makeAsset('PORT-SOURCE-B');
        $source = $this->makePort($assetA, ['name' => 'SOURCE-1']);
        $other = $this->makePort($assetB, ['name' => 'OTHER-1']);

        $byPort = collect(
            $this->actingAsAdmin()
                ->getJson(route('admin.ports.search', ['q' => 'PORT-SOURCE', 'exclude_port_id' => $source->id]))
                ->json('results'),
        )->pluck('id');

        $this->assertFalse($byPort->contains($source->id));
        $this->assertTrue($byPort->contains($other->id));

        $byAsset = collect(
            $this->actingAsAdmin()
                ->getJson(route('admin.ports.search', ['q' => 'PORT-SOURCE', 'exclude_asset_id' => $assetB->id]))
                ->json('results'),
        )->pluck('id');

        $this->assertFalse($byAsset->contains($other->id));
        $this->assertTrue($byAsset->contains($source->id));
    }

    public function test_delete_is_blocked_while_the_port_is_connected(): void
    {
        $assetA = $this->makeAsset('PORT-DEL-A');
        $assetB = $this->makeAsset('PORT-DEL-B');
        $port = $this->makePort($assetA, ['name' => 'DEL-1']);
        $peer = $this->makePort($assetB, ['name' => 'DEL-2']);
        $this->connect($port, $peer);

        $response = $this->actingAsAdmin()
            ->from(route('admin.inventory-assets.show', $assetA))
            ->delete(route('admin.inventory-assets.ports.destroy', [$assetA, $port]));

        $response->assertSessionHasErrors(['delete' => 'Disconnect the cable first.']);
        $this->assertDatabaseHas('device_ports', ['id' => $port->id]);
    }
}
