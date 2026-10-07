<?php

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\DevicePort;
use App\Models\InventoryAsset;
use App\Models\Permission;
use App\Models\PortConnection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice B2: port connections — the one-cable-per-port service, the connect /
 * disconnect / edit-cable endpoints, the connections index + CSV export, and
 * the ports.view / ports.manage permission matrix.
 */
class PortConnectionTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $cable
     */
    private function connectViaHttp(InventoryAsset $asset, DevicePort $source, DevicePort $peer, array $cable = [])
    {
        return $this->actingAsAdmin()
            ->from(route('admin.inventory-assets.show', $asset))
            ->post(route('admin.inventory-assets.port-connections.store', $asset), array_merge([
                'source_port_id' => $source->id,
                'peer_port_id' => $peer->id,
            ], $cable));
    }

    public function test_connect_creates_the_row_and_a_connected_event(): void
    {
        $assetA = $this->makeAsset('CONN-A');
        $assetB = $this->makeAsset('CONN-B');
        $source = $this->makePort($assetA, ['name' => 'Gi1/0/1']);
        $peer = $this->makePort($assetB, ['name' => 'Gi1/0/2']);

        $response = $this->connectViaHttp($assetA, $source, $peer, [
            'cable_label' => 'C-1',
            'cable_type' => 'cat6',
            'cable_length_m' => 5,
        ]);

        $response->assertSessionHasNoErrors();

        $connection = PortConnection::query()->firstOrFail();
        $this->assertSame($source->id, $connection->port_a_id);
        $this->assertSame($peer->id, $connection->port_b_id);
        $this->assertSame('C-1', $connection->cable_label);

        $this->assertDatabaseHas('port_connection_events', [
            'port_connection_id' => $connection->id,
            'action' => 'connected',
            'a_asset_tag' => 'CONN-A',
            'a_port_name' => 'Gi1/0/1',
            'b_asset_tag' => 'CONN-B',
            'b_port_name' => 'Gi1/0/2',
        ]);
    }

    public function test_connect_rejects_the_same_port(): void
    {
        $asset = $this->makeAsset('SAME-A');
        $port = $this->makePort($asset, ['name' => 'Gi1/0/1']);

        $response = $this->connectViaHttp($asset, $port, $port);

        $response->assertSessionHasErrors('connection');
        $this->assertDatabaseCount('port_connections', 0);
    }

    public function test_connect_rejects_a_port_already_used_in_the_same_column(): void
    {
        $assetA = $this->makeAsset('SAME-COL-A');
        $assetB = $this->makeAsset('SAME-COL-B');
        $assetC = $this->makeAsset('SAME-COL-C');
        $a = $this->makePort($assetA, ['name' => 'A1']);
        $b = $this->makePort($assetB, ['name' => 'B1']);
        $c = $this->makePort($assetC, ['name' => 'C1']);

        $this->connectViaHttp($assetA, $a, $b)->assertSessionHasNoErrors();

        // `a` is now port_a_id; a second cable from `a` must be refused.
        $response = $this->connectViaHttp($assetA, $a, $c);

        $response->assertSessionHasErrors('connection');
        $this->assertDatabaseCount('port_connections', 1);
    }

    public function test_connect_rejects_a_port_already_used_in_the_opposite_column(): void
    {
        $assetA = $this->makeAsset('CROSS-COL-A');
        $assetB = $this->makeAsset('CROSS-COL-B');
        $assetC = $this->makeAsset('CROSS-COL-C');
        $a = $this->makePort($assetA, ['name' => 'A1']);
        $b = $this->makePort($assetB, ['name' => 'B1']);
        $c = $this->makePort($assetC, ['name' => 'C1']);

        $this->connectViaHttp($assetA, $a, $b)->assertSessionHasNoErrors();

        // `b` sits in the port_b_id column of the first row; the cross-column
        // case the two unique indexes alone would miss.
        $response = $this->connectViaHttp($assetB, $b, $c);

        $response->assertSessionHasErrors('connection');
        $this->assertDatabaseCount('port_connections', 1);
    }

    public function test_connect_persists_canonical_order_regardless_of_input_order(): void
    {
        $assetA = $this->makeAsset('CANON-A');
        $assetB = $this->makeAsset('CANON-B');
        $lower = $this->makePort($assetA, ['name' => 'LOW-1']);
        $higher = $this->makePort($assetB, ['name' => 'HIGH-1']);

        // Send the higher id as the source so the service must reorder.
        $this->connectViaHttp($assetB, $higher, $lower)->assertSessionHasNoErrors();

        $connection = PortConnection::query()->firstOrFail();
        $this->assertSame($lower->id, $connection->port_a_id);
        $this->assertSame($higher->id, $connection->port_b_id);
        $this->assertLessThan($connection->port_b_id, $connection->port_a_id);
    }

    public function test_update_cable_writes_a_cable_updated_event(): void
    {
        $assetA = $this->makeAsset('CABLE-A');
        $assetB = $this->makeAsset('CABLE-B');
        $source = $this->makePort($assetA, ['name' => 'CA-1']);
        $peer = $this->makePort($assetB, ['name' => 'CB-1']);
        $this->connectViaHttp($assetA, $source, $peer)->assertSessionHasNoErrors();

        $connection = PortConnection::query()->firstOrFail();

        $response = $this->actingAsAdmin()->put(route('admin.port-connections.update', $connection), [
            'cable_label' => 'C-2',
            'cable_type' => 'cat6a',
            'cable_color' => 'blue',
            'notes' => 'patched to core',
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('port_connections', [
            'id' => $connection->id,
            'cable_label' => 'C-2',
            'cable_type' => 'cat6a',
            'cable_color' => 'blue',
        ]);

        $this->assertDatabaseHas('port_connection_events', [
            'port_connection_id' => $connection->id,
            'action' => 'cable_updated',
            'cable_label' => 'C-2',
        ]);
    }

    public function test_disconnect_deletes_the_row_and_snapshots_the_endpoints(): void
    {
        $assetA = $this->makeAsset('DISC-A');
        $assetB = $this->makeAsset('DISC-B');
        $source = $this->makePort($assetA, ['name' => 'PA']);
        $peer = $this->makePort($assetB, ['name' => 'PB']);
        $this->connectViaHttp($assetA, $source, $peer)->assertSessionHasNoErrors();

        $connection = PortConnection::query()->firstOrFail();

        $response = $this->actingAsAdmin()->delete(route('admin.port-connections.destroy', $connection));

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('port_connections', ['id' => $connection->id]);
        $this->assertDatabaseHas('port_connection_events', [
            'port_connection_id' => $connection->id,
            'action' => 'disconnected',
            'a_asset_tag' => 'DISC-A',
            'a_port_name' => 'PA',
            'b_asset_tag' => 'DISC-B',
            'b_port_name' => 'PB',
        ]);
    }

    public function test_index_renders_and_applies_the_filters(): void
    {
        $dcOne = Datacenter::create(['name' => 'DC-ONE', 'code' => 'DC1']);
        $dcTwo = Datacenter::create(['name' => 'DC-TWO', 'code' => 'DC2']);

        $a1 = $this->makeAsset('IDX-A1', ['datacenter_id' => $dcOne->id]);
        $a2 = $this->makeAsset('IDX-A2', ['datacenter_id' => $dcOne->id]);
        $b1 = $this->makeAsset('IDX-B1', ['datacenter_id' => $dcTwo->id]);
        $b2 = $this->makeAsset('IDX-B2', ['datacenter_id' => $dcTwo->id]);

        $this->connectViaHttp($a1, $this->makePort($a1, ['name' => 'IA1']), $this->makePort($a2, ['name' => 'IA2']), [
            'cable_label' => 'CABLE-ONE',
            'cable_type' => 'cat6',
        ])->assertSessionHasNoErrors();

        $this->connectViaHttp($b1, $this->makePort($b1, ['name' => 'IB1']), $this->makePort($b2, ['name' => 'IB2']), [
            'cable_label' => 'CABLE-TWO',
            'cable_type' => 'cat5e',
        ])->assertSessionHasNoErrors();

        $this->actingAsAdmin()->get(route('admin.port-connections.index'))
            ->assertOk()
            ->assertSee('CABLE-ONE')
            ->assertSee('CABLE-TWO');

        $this->actingAsAdmin()->get(route('admin.port-connections.index', ['cable_type' => 'cat6']))
            ->assertOk()
            ->assertSee('CABLE-ONE')
            ->assertDontSee('CABLE-TWO');

        $this->actingAsAdmin()->get(route('admin.port-connections.index', ['datacenter_id' => $dcTwo->id]))
            ->assertOk()
            ->assertSee('CABLE-TWO')
            ->assertDontSee('CABLE-ONE');

        $this->actingAsAdmin()->get(route('admin.port-connections.index', ['search' => 'CABLE-ONE']))
            ->assertOk()
            ->assertSee('CABLE-ONE')
            ->assertDontSee('CABLE-TWO');
    }

    public function test_export_streams_csv_with_headers_and_rows(): void
    {
        $assetA = $this->makeAsset('EXP-A');
        $assetB = $this->makeAsset('EXP-B');
        $this->connectViaHttp(
            $assetA,
            $this->makePort($assetA, ['name' => 'EA-1']),
            $this->makePort($assetB, ['name' => 'EB-1']),
            ['cable_label' => 'EXP-1', 'cable_type' => 'cat6'],
        )->assertSessionHasNoErrors();

        $response = $this->actingAsAdmin()->get(route('admin.port-connections.export'));

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString(
            'id,cable_label,cable_type,cable_length_m,cable_color,a_asset_tag,a_port,b_asset_tag,b_port,a_datacenter,updated_at',
            $content,
        );
        $this->assertStringContainsString('EXP-1', $content);
        $this->assertStringContainsString('EXP-A', $content);
        $this->assertStringContainsString('EB-1', $content);
    }

    public function test_export_applies_the_filters(): void
    {
        $assetA = $this->makeAsset('EXP-F-A');
        $assetB = $this->makeAsset('EXP-F-B');
        $this->connectViaHttp(
            $assetA,
            $this->makePort($assetA, ['name' => 'EFA-1']),
            $this->makePort($assetB, ['name' => 'EFB-1']),
            ['cable_label' => 'EXPF-ONE', 'cable_type' => 'cat6'],
        )->assertSessionHasNoErrors();

        $assetC = $this->makeAsset('EXP-F-C');
        $assetD = $this->makeAsset('EXP-F-D');
        $this->connectViaHttp(
            $assetC,
            $this->makePort($assetC, ['name' => 'EFC-1']),
            $this->makePort($assetD, ['name' => 'EFD-1']),
            ['cable_label' => 'EXPF-TWO', 'cable_type' => 'cat5e'],
        )->assertSessionHasNoErrors();

        $response = $this->actingAsAdmin()->get(route('admin.port-connections.export', ['cable_type' => 'cat6']));

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString(
            'id,cable_label,cable_type,cable_length_m,cable_color,a_asset_tag,a_port,b_asset_tag,b_port,a_datacenter,updated_at',
            $content,
        );
        $this->assertStringContainsString('EXPF-ONE', $content);
        $this->assertStringNotContainsString('EXPF-TWO', $content);
    }

    public function test_view_only_user_can_open_the_index_but_sees_no_actions(): void
    {
        $viewerRole = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $viewerRole->permissions()->syncWithoutDetaching([
            Permission::firstOrCreate(['name' => 'ports.view'], ['label' => 'View Ports & Connections'])->id,
        ]);

        $user = User::factory()->create();
        $user->assignRole('viewer');

        $response = $this->actingAs($user)->get(route('admin.port-connections.index'));

        $response->assertOk();
        $response->assertDontSee('Edit cable');
        $response->assertDontSee('Disconnect');
    }

    public function test_ports_manage_implies_ports_view_on_the_index(): void
    {
        $staffRole = Role::firstOrCreate(['name' => 'staff'], ['label' => 'Staff']);
        $staffRole->permissions()->syncWithoutDetaching([
            Permission::firstOrCreate(['name' => 'ports.manage'], ['label' => 'Manage Ports & Connections'])->id,
        ]);

        $user = User::factory()->create();
        $user->assignRole('staff');

        $this->actingAs($user)
            ->get(route('admin.port-connections.index'))
            ->assertOk();
    }

    public function test_a_user_without_ports_view_is_forbidden(): void
    {
        // Panel access with an unrelated permission, but no ports.view.
        $viewerRole = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $viewerRole->permissions()->syncWithoutDetaching([
            Permission::firstOrCreate(['name' => 'inventory.view'], ['label' => 'View Inventory'])->id,
        ]);

        $user = User::factory()->create();
        $user->assignRole('viewer');

        $this->actingAs($user)
            ->get(route('admin.port-connections.index'))
            ->assertForbidden();
    }

    public function test_show_page_renders_both_sides_of_a_same_asset_cable(): void
    {
        $asset = $this->makeAsset('SAME-ASSET');
        $left = $this->makePort($asset, ['name' => 'Gi1/0/1']);
        $right = $this->makePort($asset, ['name' => 'Gi1/0/2']);

        $this->connectViaHttp($asset, $left, $right)->assertSessionHasNoErrors();

        $response = $this->actingAsAdmin()->get(route('admin.inventory-assets.show', $asset));

        $response->assertOk();
        $response->assertSee('2 connected');

        $html = $response->getContent();

        // Each local side resolves its own peer: the B-side row must render the
        // peer port name, not fall back to '—'.
        $this->assertStringContainsString('<div class="small text-muted">Gi1/0/2</div>', $html);
        $this->assertStringContainsString('<div class="small text-muted">Gi1/0/1</div>', $html);

        // Both rows offer a disconnect control; neither offers connect.
        $this->assertSame(2, substr_count($html, 'aria-label="Disconnect cable"'));
        $this->assertSame(0, substr_count($html, 'aria-label="Connect port"'));
    }

    public function test_show_page_renders_the_local_side_of_a_cross_asset_cable(): void
    {
        $local = $this->makeAsset('CROSS-LOCAL-A');
        $remote = $this->makeAsset('CROSS-LOCAL-B');
        $localPort = $this->makePort($local, ['name' => 'CA-1']);
        $remotePort = $this->makePort($remote, ['name' => 'CB-1']);

        $this->connectViaHttp($local, $localPort, $remotePort)->assertSessionHasNoErrors();

        $response = $this->actingAsAdmin()->get(route('admin.inventory-assets.show', $local));

        $response->assertOk();
        $response->assertSee('1 connected');

        $html = $response->getContent();

        // Only the local side is a row here; it renders its remote peer once.
        $this->assertStringContainsString('<div class="small text-muted">CB-1</div>', $html);
        $this->assertSame(1, substr_count($html, 'aria-label="Disconnect cable"'));
        $this->assertSame(0, substr_count($html, 'aria-label="Connect port"'));
    }
}
