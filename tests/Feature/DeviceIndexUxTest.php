<?php

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\IpAddress;
use App\Models\IpAllocationHistory;
use App\Models\IpSubnet;
use App\Models\Manufacturer;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceIndexUxTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role and inventory permissions in the test DB.
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'devices.view'], ['label' => 'View Devices']);
        $manage = Permission::firstOrCreate(['name' => 'devices.manage'], ['label' => 'Manage Devices']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function datacenter(string $code = 'TST1'): Datacenter
    {
        return Datacenter::create(['name' => "Datacenter {$code}", 'code' => $code, 'status' => 'active']);
    }

    private function rack(Datacenter $datacenter, string $name = 'Rack A', int $uHeight = 42): Rack
    {
        return Rack::create([
            'datacenter_id' => $datacenter->id,
            'name' => $name,
            'u_height' => $uHeight,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(array $overrides = []): Device
    {
        $this->assetSequence++;

        $category = $overrides['category'] ?? 'server';
        unset($overrides['category']);

        if (! array_key_exists('device_type_id', $overrides)) {
            $overrides['device_type_id'] = (int) DeviceType::factory()->create(['category' => $category])->id;
        }

        return Device::create(array_merge([
            'asset_tag' => 'AST-'.$this->assetSequence,
        ], $overrides));
    }

    private function subnet(string $name = 'Subnet A'): IpSubnet
    {
        return IpSubnet::create(['name' => $name, 'subnet_cidr' => '10.0.0.0/24']);
    }

    public function test_index_renders_datacenter_and_rack_filters_and_filters_rows(): void
    {
        $datacenterA = $this->datacenter('DCAA');
        $datacenterB = $this->datacenter('DCBB');
        $rackA = $this->rack($datacenterA, 'Rack Alpha');
        $rackB = $this->rack($datacenterB, 'Rack Beta');
        $this->makeAsset(['asset_tag' => 'MATCH-DCA', 'datacenter_id' => $datacenterA->id, 'rack_id' => $rackA->id]);
        $this->makeAsset(['asset_tag' => 'OTHER-DCB', 'datacenter_id' => $datacenterB->id, 'rack_id' => $rackB->id]);

        $response = $this->actingAsAdmin()->get('/admin/devices');
        $response->assertOk()
            ->assertSee('name="category"', false)
            ->assertSee('name="datacenter_id"', false)
            ->assertSee('name="rack_id"', false);

        // The three custom filters must live inside the datatable toolbar form
        // (not a separate nested form), so a toolbar search/status submit keeps
        // them and they all submit together.
        $html = $response->getContent();
        preg_match('/id="(grid-form-[^"]+)"/', $html, $formMatch);
        $this->assertNotEmpty($formMatch, 'The datatable toolbar form must be rendered.');
        $formId = $formMatch[1];
        $formStart = strpos($html, 'id="'.$formId.'"');
        $formEnd = $formStart === false ? false : strpos($html, '</form>', $formStart);
        $this->assertNotFalse($formStart, 'The datatable toolbar form must be rendered.');
        $this->assertNotFalse($formEnd, 'The datatable toolbar form must be closed.');
        $this->assertSame(1, substr_count($html, 'id="'.$formId.'"'), 'The filters must not sit in a second standalone form.');
        foreach (['category', 'datacenter_id', 'rack_id'] as $field) {
            $position = strpos($html, 'name="'.$field.'"');
            $this->assertNotFalse($position, "Filter control [{$field}] must be rendered.");
            $this->assertTrue($position > $formStart && $position < $formEnd, "Filter [{$field}] must sit inside the toolbar form so it submits with search.");
        }

        $this->actingAsAdmin()->get('/admin/devices?datacenter_id='.$datacenterA->id)
            ->assertOk()
            ->assertSee('MATCH-DCA')
            ->assertDontSee('OTHER-DCB');

        $this->actingAsAdmin()->get('/admin/devices?rack_id='.$rackA->id)
            ->assertOk()
            ->assertSee('MATCH-DCA')
            ->assertDontSee('OTHER-DCB');
    }

    public function test_export_streams_csv_with_expected_header_and_rows(): void
    {
        $datacenterA = $this->datacenter('DCAA');
        $datacenterB = $this->datacenter('DCBB');
        $rackA = $this->rack($datacenterA, 'Rack Alpha');
        $this->makeAsset([
            'asset_tag' => 'AST-EXPORT',
            'category' => 'switch',
            'serial_number' => 'SN-123',
            'model' => 'Catalyst 9300',
            'manufacturer' => 'Cisco',
            'vendor' => 'Ingram',
            'status' => 'in_stock',
            'datacenter_id' => $datacenterA->id,
            'rack_id' => $rackA->id,
            'rack_u_position' => 7,
            'purchase_date' => '2026-01-15',
            'purchase_cost' => '750.50',
            'warranty_expiry' => '2029-01-15',
        ]);
        $this->makeAsset(['asset_tag' => 'AST-OTHER', 'category' => 'server', 'datacenter_id' => $datacenterB->id]);

        $response = $this->actingAsAdmin()->get('/admin/devices/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('devices-', (string) $response->headers->get('content-disposition'));

        $content = $response->streamedContent();
        $this->assertStringContainsString(
            'asset_tag,category,serial_number,model,manufacturer,vendor,status,datacenter,rack,rack_u_position,purchase_date,purchase_cost,warranty_expiry',
            $content,
        );
        $this->assertStringContainsString(
            'AST-EXPORT,switch,SN-123,"Catalyst 9300",Cisco,Ingram,in_stock,"Datacenter DCAA","Rack Alpha",7,2026-01-15,750.50,2029-01-15',
            $content,
        );
        $this->assertStringContainsString('AST-OTHER', $content);
    }

    public function test_export_honours_the_active_filter(): void
    {
        $datacenterA = $this->datacenter('DCAA');
        $datacenterB = $this->datacenter('DCBB');
        $this->makeAsset(['asset_tag' => 'AST-KEEP', 'datacenter_id' => $datacenterA->id]);
        $this->makeAsset(['asset_tag' => 'AST-DROP', 'datacenter_id' => $datacenterB->id]);

        $response = $this->actingAsAdmin()->get('/admin/devices/export?datacenter_id='.$datacenterA->id);

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('AST-KEEP', $content);
        $this->assertStringNotContainsString('AST-DROP', $content);
    }

    public function test_bulk_status_updates_only_the_selected_ids(): void
    {
        $first = $this->makeAsset(['asset_tag' => 'AST-A', 'status' => 'in_stock']);
        $second = $this->makeAsset(['asset_tag' => 'AST-B', 'status' => 'in_stock']);
        $untouched = $this->makeAsset(['asset_tag' => 'AST-C', 'status' => 'in_stock']);

        $response = $this->actingAsAdmin()->post('/admin/devices/bulk-status', [
            'ids' => [$first->id, $second->id],
            'status' => 'retired',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Updated 2 devices.');

        $this->assertDatabaseHas('devices', ['id' => $first->id, 'status' => 'retired']);
        $this->assertDatabaseHas('devices', ['id' => $second->id, 'status' => 'retired']);
        $this->assertDatabaseHas('devices', ['id' => $untouched->id, 'status' => 'in_stock']);
    }

    public function test_bulk_status_rejects_an_unknown_status(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-A', 'status' => 'in_stock']);

        $response = $this->actingAsAdmin()->post('/admin/devices/bulk-status', [
            'ids' => [$asset->id],
            'status' => 'not_a_status',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertDatabaseHas('devices', ['id' => $asset->id, 'status' => 'in_stock']);
    }

    public function test_index_renders_delete_confirmation_markup_for_a_row(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-DELETE-ME']);

        $response = $this->actingAsAdmin()->get('/admin/devices');

        $response->assertOk();
        $response->assertSee('delete-device-'.$asset->id, false);
        $response->assertSee(route('admin.devices.destroy', $asset), false);
        $response->assertSee('Delete device');
    }

    public function test_show_renders_a_linked_ip_address_and_recent_history(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-IP']);
        $subnet = $this->subnet('Management VLAN');
        $ip = IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => '10.0.0.10',
            'type' => 'assigned',
            'device_id' => $asset->id,
            'last_seen_at' => now(),
        ]);
        IpAllocationHistory::create([
            'ip_address_id' => $ip->id,
            'action' => 'allocated',
            'ip_address_snapshot' => '10.0.0.10',
            'changed_at' => now(),
            'notes' => 'Allocated to asset',
        ]);

        $response = $this->actingAsAdmin()->get('/admin/devices/'.$asset->id);

        $response->assertOk();
        $response->assertSee('IP Addresses');
        $response->assertSee('10.0.0.10');
        $response->assertSee('Management VLAN');
        $response->assertSee('Recent IP allocation history');
        $response->assertSee('Allocated to asset');
        $response->assertSee(route('admin.ip-addresses.show', $ip), false);
    }

    public function test_index_renders_the_device_type_column_as_a_link(): void
    {
        $manufacturer = Manufacturer::factory()->create(['name' => 'Dell', 'slug' => 'dell']);
        $deviceType = DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'slug' => 'dell-poweredge-r750',
        ]);
        $this->makeAsset(['asset_tag' => 'AST-DT-LINK', 'device_type_id' => $deviceType->id]);

        $response = $this->actingAsAdmin()->get('/admin/devices');

        $response->assertOk()
            ->assertSee('Device Type')
            ->assertSee(route('admin.device-types.show', $deviceType), false)
            ->assertSee('Dell — PowerEdge R750');
    }

    public function test_index_hides_management_controls_from_view_only_users(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-VIEWONLY']);

        // `support` holds devices.view but not devices.manage.
        $user = User::factory()->create();
        $user->assignRole('support');

        $response = $this->actingAs($user)->get('/admin/devices');

        $response->assertOk();
        $response->assertSee('AST-VIEWONLY');

        $response->assertDontSee('Add Asset');
        $response->assertDontSee(route('admin.devices.create'), false);
        $response->assertDontSee(route('admin.devices.edit', $asset), false);
    }
}
