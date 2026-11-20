<?php

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceFaceTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'devices.view'], ['label' => 'View Devices']);
        $manage = Permission::firstOrCreate(['name' => 'devices.manage'], ['label' => 'Manage Devices']);
        $viewRacks = Permission::firstOrCreate(['name' => 'racks.view'], ['label' => 'View Racks']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id, $viewRacks->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        if (! array_key_exists('device_type_id', $overrides)) {
            $overrides['device_type_id'] = (int) DeviceType::factory()->create(['category' => 'server'])->id;
        }

        return array_merge([
            'asset_tag' => 'FACE-0001',
            'model' => 'PowerEdge R750',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(array $overrides = []): Device
    {
        if (! array_key_exists('device_type_id', $overrides)) {
            $overrides['device_type_id'] = (int) DeviceType::factory()->create(['category' => 'server'])->id;
        }

        return Device::create(array_merge([
            'asset_tag' => 'FACE-'.uniqid(),
        ], $overrides));
    }

    public function test_creating_a_device_with_rear_face_persists(): void
    {
        $device = $this->makeAsset(['face' => 'rear']);

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'face' => 'rear',
        ]);
        $this->assertSame('rear', $device->refresh()->face);
    }

    public function test_invalid_face_is_rejected_with_422(): void
    {
        $response = $this->actingAsAdmin()->postJson('/admin/devices', $this->validPayload([
            'face' => 'sideways',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['face']);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_updating_face_to_front_persists(): void
    {
        $asset = $this->makeAsset(['face' => 'rear']);

        $response = $this->actingAsAdmin()->put("/admin/devices/{$asset->id}", $this->validPayload([
            'asset_tag' => $asset->asset_tag,
            'device_type_id' => $asset->device_type_id,
            'face' => 'front',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame('front', $asset->refresh()->face);
    }

    public function test_rack_elevation_renders_for_rear_face(): void
    {
        $datacenter = Datacenter::create(['name' => 'Face DC', 'code' => 'FDC']);
        $rack = Rack::create([
            'datacenter_id' => $datacenter->id,
            'name' => 'Rack Face',
            'u_height' => 42,
            'status' => 'active',
        ]);
        $this->makeAsset([
            'asset_tag' => 'FACE-REAR-1',
            'rack_id' => $rack->id,
            'rack_u_position' => 10,
            'face' => 'rear',
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.racks.show', $rack).'?face=rear');

        $response->assertOk();
        $response->assertSee('Rear');
        $response->assertSee('FACE-REAR-1');
    }
}
