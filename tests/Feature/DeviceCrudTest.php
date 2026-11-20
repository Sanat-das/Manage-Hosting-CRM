<?php

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Manufacturer;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceCrudTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        if (! array_key_exists('device_type_id', $overrides)) {
            $overrides['device_type_id'] = $this->makeDeviceTypeId();
        }

        return array_merge([
            'asset_tag' => 'AST-0001',
            'model' => 'PowerEdge R750',
        ], $overrides);
    }

    private function makeDeviceTypeId(string $category = 'server'): int
    {
        return (int) DeviceType::factory()->create(['category' => $category])->id;
    }

    private function datacenter(string $code = 'TST1'): Datacenter
    {
        return Datacenter::create(['name' => "Datacenter {$code}", 'code' => $code, 'status' => 'active']);
    }

    private function rack(Datacenter $datacenter, int $uHeight = 42, string $name = 'Rack A'): Rack
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
            $overrides['device_type_id'] = $this->makeDeviceTypeId($category);
        }

        return Device::create(array_merge([
            'asset_tag' => 'AST-'.$this->assetSequence,
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/admin/devices')->assertRedirect();
    }

    public function test_store_is_forbidden_without_manage_permission(): void
    {
        $user = User::factory()->create();

        // The seeded admin role is a superuser; remove only the route's gate so
        // the refusal comes from the devices.manage check, not the panel door
        // (which still sees the role's other permissions).
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $adminRole->permissions()->detach(
            Permission::where('name', 'devices.manage')->firstOrFail()->id,
        );

        $user->assignRole('admin');

        $this->actingAs($user)
            ->post('/admin/devices', $this->validPayload())
            ->assertForbidden();
    }

    public function test_admin_can_create_asset_with_vendor_and_purchase_cost(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/devices', $this->validPayload([
            'vendor' => 'Dell EMC',
            'purchase_cost' => '1499.99',
        ]));

        $response->assertRedirect(route('admin.devices.index'));
        $this->assertDatabaseHas('devices', [
            'asset_tag' => 'AST-0001',
            'vendor' => 'Dell EMC',
            'purchase_cost' => '1499.99',
        ]);
    }

    public function test_update_persists_every_editable_field(): void
    {
        $asset = $this->makeAsset();
        $cisco = Manufacturer::factory()->create(['name' => 'Cisco']);
        $switchTypeId = (int) DeviceType::factory()->create(['category' => 'switch', 'manufacturer_id' => $cisco->id])->id;

        $response = $this->actingAsAdmin()->put("/admin/devices/{$asset->id}", [
            'asset_tag' => $asset->asset_tag,
            'device_type_id' => $switchTypeId,
            'manufacturer' => 'Cisco',
            'purchase_date' => '2026-01-15',
            'vendor' => 'Ingram Micro',
            'purchase_cost' => '750.50',
            'warranty_expiry' => '2029-01-15',
        ]);

        $response->assertRedirect(route('admin.devices.show', $asset));

        $asset->refresh();
        $this->assertSame($switchTypeId, $asset->device_type_id);
        $this->assertSame('Cisco', $asset->manufacturer);
        $this->assertSame('2026-01-15', $asset->purchase_date->format('Y-m-d'));
        $this->assertSame('Ingram Micro', $asset->vendor);
        $this->assertSame('750.50', $asset->purchase_cost);
        $this->assertSame('2029-01-15', $asset->warranty_expiry->format('Y-m-d'));
    }

    public function test_u_position_greater_than_rack_height_is_rejected(): void
    {
        $rack = $this->rack($this->datacenter(), 42);

        $response = $this->actingAsAdmin()->post('/admin/devices', $this->validPayload([
            'rack_id' => $rack->id,
            'rack_u_position' => 43,
        ]));

        $response->assertSessionHasErrors([
            'rack_u_position' => 'The U position must not exceed the rack height of 42.',
        ]);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_u_position_equal_to_rack_height_is_accepted(): void
    {
        $rack = $this->rack($this->datacenter(), 42);

        $response = $this->actingAsAdmin()->post('/admin/devices', $this->validPayload([
            'rack_id' => $rack->id,
            'rack_u_position' => 42,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', [
            'asset_tag' => 'AST-0001',
            'rack_id' => $rack->id,
            'rack_u_position' => 42,
        ]);
    }

    public function test_duplicate_position_in_same_rack_is_rejected(): void
    {
        $rack = $this->rack($this->datacenter());
        $this->makeAsset(['asset_tag' => 'AST-EXISTING', 'rack_id' => $rack->id, 'rack_u_position' => 5]);

        $response = $this->actingAsAdmin()->post('/admin/devices', $this->validPayload([
            'rack_id' => $rack->id,
            'rack_u_position' => 5,
        ]));

        $response->assertSessionHasErrors([
            'rack_u_position' => 'That U position is already occupied in the selected rack.',
        ]);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_same_position_in_a_different_rack_is_allowed(): void
    {
        $datacenter = $this->datacenter();
        $rackA = $this->rack($datacenter, 42, 'Rack A');
        $rackB = $this->rack($datacenter, 42, 'Rack B');
        $this->makeAsset(['asset_tag' => 'AST-EXISTING', 'rack_id' => $rackA->id, 'rack_u_position' => 5]);

        $response = $this->actingAsAdmin()->post('/admin/devices', $this->validPayload([
            'rack_id' => $rackB->id,
            'rack_u_position' => 5,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', [
            'asset_tag' => 'AST-0001',
            'rack_id' => $rackB->id,
            'rack_u_position' => 5,
        ]);
    }

    public function test_updating_an_asset_with_its_own_position_is_allowed(): void
    {
        $rack = $this->rack($this->datacenter());
        $asset = $this->makeAsset(['asset_tag' => 'AST-EXISTING', 'rack_id' => $rack->id, 'rack_u_position' => 5]);

        $response = $this->actingAsAdmin()->put("/admin/devices/{$asset->id}", [
            'asset_tag' => 'AST-EXISTING',
            'vendor' => 'Rack Vendor',
            'rack_id' => $rack->id,
            'rack_u_position' => 5,
            'device_type_id' => $asset->device_type_id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', [
            'id' => $asset->id,
            'vendor' => 'Rack Vendor',
            'rack_u_position' => 5,
        ]);
    }

    public function test_position_without_a_rack_is_rejected(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/devices', $this->validPayload([
            'rack_u_position' => 1,
        ]));

        $response->assertSessionHasErrors([
            'rack_u_position' => 'Select a rack before setting the U position.',
        ]);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_a_soft_deleted_assets_position_is_reusable(): void
    {
        $rack = $this->rack($this->datacenter());
        $old = $this->makeAsset(['asset_tag' => 'AST-OLD', 'rack_id' => $rack->id, 'rack_u_position' => 5]);
        $old->delete();

        $response = $this->actingAsAdmin()->post('/admin/devices', $this->validPayload([
            'rack_id' => $rack->id,
            'rack_u_position' => 5,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', [
            'asset_tag' => 'AST-0001',
            'rack_id' => $rack->id,
            'rack_u_position' => 5,
        ]);
    }

    public function test_index_filters_by_category_status_and_datacenter(): void
    {
        $datacenterA = $this->datacenter('DCAA');
        $datacenterB = $this->datacenter('DCBB');
        $this->makeAsset(['asset_tag' => 'MATCH-SERVER', 'category' => 'server', 'status' => 'in_stock', 'datacenter_id' => $datacenterA->id]);
        $this->makeAsset(['asset_tag' => 'MATCH-SWITCH', 'category' => 'switch', 'status' => 'retired', 'datacenter_id' => $datacenterB->id]);

        $this->actingAsAdmin()->get('/admin/devices?category=server')
            ->assertOk()
            ->assertSee('MATCH-SERVER')
            ->assertDontSee('MATCH-SWITCH');

        $this->actingAsAdmin()->get('/admin/devices?status=retired')
            ->assertOk()
            ->assertSee('MATCH-SWITCH')
            ->assertDontSee('MATCH-SERVER');

        $this->actingAsAdmin()->get('/admin/devices?datacenter_id='.$datacenterA->id)
            ->assertOk()
            ->assertSee('MATCH-SERVER')
            ->assertDontSee('MATCH-SWITCH');
    }

    public function test_destroy_soft_deletes_an_asset(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-DELETE']);

        $response = $this->actingAsAdmin()->delete("/admin/devices/{$asset->id}");

        $response->assertRedirect(route('admin.devices.index'));
        $this->assertSoftDeleted('devices', ['id' => $asset->id]);
    }

    public function test_asset_tag_can_be_reused_after_a_soft_delete(): void
    {
        $admin = $this->actingAsAdmin();

        $store = $admin->post('/admin/devices', $this->validPayload(['asset_tag' => 'AST-REUSE']));
        $store->assertRedirect(route('admin.devices.index'));
        $store->assertSessionHasNoErrors();

        $asset = Device::where('asset_tag', 'AST-REUSE')->sole();

        $admin->delete("/admin/devices/{$asset->id}")
            ->assertRedirect(route('admin.devices.index'));

        $restore = $admin->post('/admin/devices', $this->validPayload(['asset_tag' => 'AST-REUSE']));
        $restore->assertRedirect(route('admin.devices.index'));
        $restore->assertSessionHasNoErrors();

        $this->assertSame(2, Device::withTrashed()->where('asset_tag', 'AST-REUSE')->count());
        $this->assertSame(1, Device::where('asset_tag', 'AST-REUSE')->count());
    }

    public function test_duplicate_asset_tag_on_a_live_asset_is_still_rejected(): void
    {
        $admin = $this->actingAsAdmin();

        $admin->post('/admin/devices', $this->validPayload(['asset_tag' => 'AST-LIVE-DUP']))
            ->assertSessionHasNoErrors();

        $response = $admin->post('/admin/devices', $this->validPayload(['asset_tag' => 'AST-LIVE-DUP']));

        $response->assertSessionHasErrors('asset_tag');
        $this->assertSame(1, Device::where('asset_tag', 'AST-LIVE-DUP')->count());
    }

    public function test_update_can_take_the_tag_of_a_soft_deleted_asset(): void
    {
        $admin = $this->actingAsAdmin();

        $a = $this->makeAsset(['asset_tag' => 'AST-T1']);
        $b = $this->makeAsset(['asset_tag' => 'AST-T2']);
        $a->delete();

        $response = $admin->put("/admin/devices/{$b->id}", ['asset_tag' => 'AST-T1', 'device_type_id' => $b->device_type_id]);

        $response->assertRedirect(route('admin.devices.show', $b));
        $response->assertSessionHasNoErrors();
        $this->assertSame('AST-T1', $b->fresh()->asset_tag);
    }

    public function test_update_rejects_the_tag_of_another_live_asset(): void
    {
        $admin = $this->actingAsAdmin();

        $this->makeAsset(['asset_tag' => 'AST-LIVE-1']);
        $b = $this->makeAsset(['asset_tag' => 'AST-LIVE-2']);

        $response = $admin->put("/admin/devices/{$b->id}", ['asset_tag' => 'AST-LIVE-1', 'device_type_id' => $b->device_type_id]);

        $response->assertSessionHasErrors('asset_tag');
        $this->assertSame('AST-LIVE-2', $b->fresh()->asset_tag);
    }

    public function test_database_rejects_two_live_assets_with_the_same_tag(): void
    {
        $this->makeAsset(['asset_tag' => 'AST-DB-UNIQUE']);

        $this->expectException(QueryException::class);

        $this->makeAsset(['asset_tag' => 'AST-DB-UNIQUE']);
    }

    public function test_moving_an_asset_onto_an_occupied_position_is_rejected(): void
    {
        $rack = $this->rack($this->datacenter());
        $holder = $this->makeAsset(['asset_tag' => 'AST-HOLDER', 'rack_id' => $rack->id, 'rack_u_position' => 7]);
        $mover = $this->makeAsset(['asset_tag' => 'AST-MOVER', 'rack_id' => $rack->id, 'rack_u_position' => 9]);

        $response = $this->actingAsAdmin()->put("/admin/devices/{$mover->id}", [
            'asset_tag' => 'AST-MOVER',
            'rack_id' => $rack->id,
            'rack_u_position' => 7,
            'device_type_id' => $mover->device_type_id,
        ]);

        $response->assertSessionHasErrors([
            'rack_u_position' => 'That U position is already occupied in the selected rack.',
        ]);
        $this->assertDatabaseHas('devices', ['id' => $holder->id, 'rack_u_position' => 7]);
        $this->assertDatabaseHas('devices', ['id' => $mover->id, 'rack_u_position' => 9]);
    }

    public function test_create_form_renders_vendor_and_purchase_cost_fields(): void
    {
        $this->actingAsAdmin()->get('/admin/devices/create')
            ->assertOk()
            ->assertSee('name="vendor"', false)
            ->assertSee('name="purchase_cost"', false);
    }

    public function test_edit_form_renders_every_editable_field(): void
    {
        $asset = $this->makeAsset(['manufacturer' => 'Dell', 'vendor' => 'Dell EMC']);

        $this->actingAsAdmin()->get("/admin/devices/{$asset->id}/edit")
            ->assertOk()
            ->assertSee('name="device_type_id"', false)
            ->assertSee('name="manufacturer"', false)
            ->assertSee('name="purchase_date"', false)
            ->assertSee('name="vendor"', false)
            ->assertSee('name="purchase_cost"', false)
            ->assertSee('name="warranty_expiry"', false);
    }

    public function test_updating_a_legacy_asset_with_a_position_but_no_rack_is_allowed(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-LEGACY-POS', 'rack_u_position' => 12]);

        $response = $this->actingAsAdmin()->put("/admin/devices/{$asset->id}", [
            'asset_tag' => 'AST-LEGACY-POS',
            'vendor' => 'Legacy Vendor',
            'rack_u_position' => 12,
            'device_type_id' => $asset->device_type_id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', [
            'id' => $asset->id,
            'vendor' => 'Legacy Vendor',
            'rack_u_position' => 12,
        ]);
    }

    public function test_moving_a_legacy_position_without_a_rack_is_still_rejected(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-LEGACY-MOVE', 'rack_u_position' => 12]);

        $response = $this->actingAsAdmin()->put("/admin/devices/{$asset->id}", [
            'asset_tag' => 'AST-LEGACY-MOVE',
            'rack_u_position' => 13,
            'device_type_id' => $asset->device_type_id,
        ]);

        $response->assertSessionHasErrors('rack_u_position');
        $this->assertDatabaseHas('devices', ['id' => $asset->id, 'rack_u_position' => 12]);
    }

    public function test_bulk_status_updates_valid_ids(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-BULK', 'status' => 'in_stock']);

        $response = $this->actingAsAdmin()->post('/admin/devices/bulk-status', [
            'ids' => [$asset->id],
            'status' => 'retired',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', ['id' => $asset->id, 'status' => 'retired']);
    }

    public function test_bulk_status_rejects_an_unknown_id(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-KNOWN', 'status' => 'in_stock']);

        $response = $this->actingAsAdmin()->post('/admin/devices/bulk-status', [
            'ids' => [$asset->id, 999999],
            'status' => 'retired',
        ]);

        $response->assertSessionHasErrors('ids.1');
        $this->assertDatabaseHas('devices', ['id' => $asset->id, 'status' => 'in_stock']);
    }

    public function test_bulk_status_rejects_a_soft_deleted_id(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-TRASHED', 'status' => 'in_stock']);
        $asset->delete();

        $response = $this->actingAsAdmin()->post('/admin/devices/bulk-status', [
            'ids' => [$asset->id],
            'status' => 'retired',
        ]);

        $response->assertSessionHasErrors('ids.0');
        $this->assertSoftDeleted('devices', ['id' => $asset->id]);
    }

    public function test_bulk_status_rejects_a_negative_id(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/devices/bulk-status', [
            'ids' => [-1],
            'status' => 'retired',
        ]);

        $response->assertSessionHasErrors('ids.0');
    }
}
