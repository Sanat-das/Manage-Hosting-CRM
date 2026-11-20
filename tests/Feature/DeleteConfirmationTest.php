<?php

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\InventoryItem;
use App\Models\IpSubnet;
use App\Models\Manufacturer;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Role;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * View-level assertions for the typed delete confirmation (UI/UX audit fix #2):
 * the index views must render blast-radius counts inside the confirm modal and
 * require typing the object's name once the total reaches the threshold (6).
 */
class DeleteConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function grant(string ...$permissions): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach ($permissions as $permission) {
            $model = Permission::firstOrCreate(['name' => $permission], ['label' => $permission]);
            $adminRole->permissions()->syncWithoutDetaching([$model->id]);
        }
    }

    private function actingWithPermissions(string ...$permissions): self
    {
        $user = User::factory()->create();
        $this->grant(...$permissions);
        $user->assignRole('admin');

        return parent::actingAs($user);
    }

    private function makeDevice(array $attributes = []): Device
    {
        $this->sequence++;

        return Device::create(array_merge([
            'asset_tag' => 'AST-DELCONF-'.$this->sequence,
        ], $attributes));
    }

    public function test_manufacturer_index_zero_state_and_labels(): void
    {
        Manufacturer::factory()->create(['name' => 'Dell']);
        $labeled = Manufacturer::factory()->create(['name' => 'Labeled Co']);
        DeviceType::factory()->create(['manufacturer_id' => $labeled->id]);

        $this->actingWithPermissions('devices.view', 'devices.manage')
            ->get('/admin/manufacturers')
            ->assertOk()
            ->assertSee('Device types (deleted with it)')
            ->assertSee('No dependent rows — safe to delete.');
    }

    public function test_manufacturer_index_requires_typing_the_name_above_threshold(): void
    {
        $manufacturer = Manufacturer::factory()->create(['name' => 'Supermicro RackCo']);
        $device = $this->makeDevice();

        for ($i = 0; $i < 6; $i++) {
            InventoryItem::create([
                'device_id' => $device->id,
                'name' => 'Item '.$i,
                'manufacturer_id' => $manufacturer->id,
            ]);
        }

        $this->actingWithPermissions('devices.view', 'devices.manage')
            ->get('/admin/manufacturers')
            ->assertOk()
            ->assertSee('data-expected="Supermicro RackCo"', false)
            ->assertSee('typed-confirm-input', false);
    }

    public function test_manufacturer_devices_affected_counts_devices_of_its_types(): void
    {
        $manufacturer = Manufacturer::factory()->create(['name' => 'CountCo']);
        $type = DeviceType::factory()->create(['manufacturer_id' => $manufacturer->id]);
        $this->makeDevice(['device_type_id' => $type->id]);
        $this->makeDevice(['device_type_id' => $type->id]);

        $this->actingWithPermissions('devices.view', 'devices.manage')
            ->get('/admin/manufacturers')
            ->assertOk()
            ->assertSee('Devices losing device-type link')
            ->assertSee('2</strong>', false);
    }

    public function test_datacenter_index_shows_counts_and_requires_typing_above_threshold(): void
    {
        $datacenter = Datacenter::create(['name' => 'DC North', 'code' => 'DCN']);

        for ($i = 0; $i < 6; $i++) {
            IpSubnet::create(['name' => 'Subnet '.$i, 'subnet_cidr' => '10.9.'.$i.'.0/24', 'datacenter_id' => $datacenter->id]);
        }

        $this->actingWithPermissions('datacenters.view', 'datacenters.manage')
            ->get('/admin/datacenters')
            ->assertOk()
            ->assertSee('Racks in this datacenter')
            ->assertSee('Devices in this datacenter')
            ->assertSee('IP subnets in this datacenter')
            ->assertSee('data-expected="DC North"', false);
    }

    public function test_datacenter_index_hides_delete_for_view_only_users(): void
    {
        $datacenter = Datacenter::create(['name' => 'DC View', 'code' => 'DCV']);

        // The seeded admin role holds every permission on a fresh install, so
        // gate through a dedicated viewer role instead (same pattern as DcimRbacTest).
        $user = User::factory()->create();
        $viewerRole = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $view = Permission::firstOrCreate(['name' => 'datacenters.view'], ['label' => 'View datacenters']);
        $viewerRole->permissions()->syncWithoutDetaching([$view->id]);
        $user->assignRole('viewer');

        $this->actingAs($user)
            ->get('/admin/datacenters')
            ->assertOk()
            ->assertDontSee('delete-datacenter-'.$datacenter->id);
    }

    public function test_rack_index_shows_devices_count_and_zero_state_message(): void
    {
        $datacenter = Datacenter::create(['name' => 'DC Rack', 'code' => 'DCR']);
        $rack = Rack::create(['datacenter_id' => $datacenter->id, 'name' => 'Rack 42']);
        Rack::create(['datacenter_id' => $datacenter->id, 'name' => 'Rack Empty']);
        $this->makeDevice(['rack_id' => $rack->id]);

        $this->actingWithPermissions('racks.view', 'racks.manage')
            ->get('/admin/racks')
            ->assertOk()
            ->assertSee('Devices in this rack')
            ->assertSee('No dependent rows — safe to delete.');
    }

    public function test_rack_index_requires_typing_above_threshold(): void
    {
        $datacenter = Datacenter::create(['name' => 'DC Rack 2', 'code' => 'DCR2']);
        $rack = Rack::create(['datacenter_id' => $datacenter->id, 'name' => 'Rack Full']);

        for ($i = 0; $i < 6; $i++) {
            $this->makeDevice(['rack_id' => $rack->id]);
        }

        $this->actingWithPermissions('racks.view', 'racks.manage')
            ->get('/admin/racks')
            ->assertOk()
            ->assertSee('data-expected="Rack Full"', false);
    }

    public function test_vlan_index_shows_subnets_count_and_requires_typing_above_threshold(): void
    {
        $vlan = Vlan::create(['name' => 'Mgmt VLAN', 'vlan_id' => 140]);

        for ($i = 0; $i < 6; $i++) {
            IpSubnet::create(['name' => 'Vlan Subnet '.$i, 'subnet_cidr' => '10.20.'.$i.'.0/24', 'vlan_id' => $vlan->id]);
        }

        $this->actingWithPermissions('vlans.view', 'vlans.manage')
            ->get('/admin/vlans')
            ->assertOk()
            ->assertSee('IP subnets in this VLAN')
            ->assertSee('data-expected="Mgmt VLAN"', false);
    }

    public function test_vlan_index_zero_state_message_when_no_subnets(): void
    {
        $vlan = Vlan::create(['name' => 'Lonely VLAN', 'vlan_id' => 141]);

        $this->actingWithPermissions('vlans.view', 'vlans.manage')
            ->get('/admin/vlans')
            ->assertOk()
            ->assertSee('No dependent rows — safe to delete.');
    }
}
