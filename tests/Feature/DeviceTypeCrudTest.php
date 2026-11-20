<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Manufacturer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceTypeCrudTest extends TestCase
{
    use RefreshDatabase;

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

    private function manufacturer(string $name): Manufacturer
    {
        return Manufacturer::factory()->create([
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/admin/device-types')->assertRedirect();
    }

    public function test_create_is_forbidden_without_manage_permission(): void
    {
        $user = User::factory()->create();

        // The seeded admin role is a superuser; remove only the route's gate so
        // the refusal comes from the devices.manage check.
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $adminRole->permissions()->detach(
            Permission::where('name', 'devices.manage')->firstOrFail()->id,
        );

        $user->assignRole('admin');

        $this->actingAs($user)
            ->get('/admin/device-types/create')
            ->assertForbidden();
    }

    public function test_store_rejects_a_missing_category(): void
    {
        $manufacturer = $this->manufacturer('Dell');

        $response = $this->actingAsAdmin()->post('/admin/device-types', [
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
        ]);

        $response->assertSessionHasErrors('category');
        $this->assertDatabaseCount('device_types', 0);
    }

    public function test_index_searches_by_model_and_manufacturer_name(): void
    {
        $dell = $this->manufacturer('Dell');
        $hpe = $this->manufacturer('Hewlett Packard');
        DeviceType::factory()->create(['manufacturer_id' => $dell->id, 'model' => 'PowerEdge R750', 'slug' => 'dell-poweredge-r750']);
        DeviceType::factory()->create(['manufacturer_id' => $hpe->id, 'model' => 'ProLiant DL380', 'slug' => 'hpe-proliant-dl380']);

        $this->actingAsAdmin()->get('/admin/device-types?search=PowerEdge')
            ->assertOk()
            ->assertSee('PowerEdge R750')
            ->assertDontSee('ProLiant DL380');

        $this->actingAsAdmin()->get('/admin/device-types?search=Dell')
            ->assertOk()
            ->assertSee('PowerEdge R750')
            ->assertDontSee('ProLiant DL380');
    }

    public function test_create_and_edit_forms_render(): void
    {
        $manufacturer = $this->manufacturer('Dell');
        $deviceType = DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'slug' => 'dell-poweredge-r750',
        ]);

        $this->actingAsAdmin()->get('/admin/device-types/create')
            ->assertOk()
            ->assertSee('name="manufacturer_id"', false)
            ->assertSee('name="is_full_depth"', false)
            ->assertSee('name="power_draw_watts"', false)
            ->assertSee('name="category"', false);

        $this->actingAsAdmin()->get("/admin/device-types/{$deviceType->id}/edit")
            ->assertOk()
            ->assertSee('name="u_height"', false)
            ->assertSee('name="category"', false);
    }

    public function test_admin_can_create_a_device_type_with_an_auto_generated_slug(): void
    {
        $manufacturer = $this->manufacturer('Dell');

        $response = $this->actingAsAdmin()->post('/admin/device-types', [
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'category' => 'server',
            'slug' => '',
            'part_number' => 'PN-R750',
            'u_height' => 2,
            'is_full_depth' => '1',
            'power_draw_watts' => 800,
            'description' => '2U rack server',
        ]);

        $response->assertRedirect(route('admin.device-types.index'));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('device_types', [
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'slug' => 'dell-poweredge-r750',
            'part_number' => 'PN-R750',
            'u_height' => 2,
            'power_draw_watts' => 800,
            'description' => '2U rack server',
        ]);

        $this->assertTrue(DeviceType::where('model', 'PowerEdge R750')->sole()->is_full_depth);
    }

    public function test_is_full_depth_and_u_height_default_when_omitted(): void
    {
        $manufacturer = $this->manufacturer('Dell');

        $response = $this->actingAsAdmin()->post('/admin/device-types', [
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge T150',
            'category' => 'server',
        ]);

        $response->assertSessionHasNoErrors();

        $deviceType = DeviceType::where('model', 'PowerEdge T150')->sole();
        $this->assertSame(1, $deviceType->u_height);
        $this->assertTrue($deviceType->is_full_depth);
    }

    public function test_update_persists_every_editable_field(): void
    {
        $manufacturer = $this->manufacturer('Dell');
        $deviceType = DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R650',
            'slug' => 'dell-poweredge-r650',
            'is_full_depth' => true,
        ]);

        $response = $this->actingAsAdmin()->put("/admin/device-types/{$deviceType->id}", [
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R660',
            'slug' => 'dell-poweredge-r660',
            'part_number' => 'PN-R660',
            'category' => 'switch',
            'u_height' => 3,
            'is_full_depth' => '0',
            'power_draw_watts' => 650,
            'description' => 'Updated model',
        ]);

        $response->assertRedirect(route('admin.device-types.index'));
        $response->assertSessionHasNoErrors();

        $deviceType->refresh();
        $this->assertSame('PowerEdge R660', $deviceType->model);
        $this->assertSame('dell-poweredge-r660', $deviceType->slug);
        $this->assertSame('PN-R660', $deviceType->part_number);
        $this->assertSame('switch', $deviceType->category);
        $this->assertSame(3, $deviceType->u_height);
        $this->assertFalse($deviceType->is_full_depth);
        $this->assertSame(650, $deviceType->power_draw_watts);
        $this->assertSame('Updated model', $deviceType->description);
    }

    public function test_update_falls_back_to_one_u_when_the_height_is_cleared(): void
    {
        $manufacturer = $this->manufacturer('Dell');
        $deviceType = DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'slug' => 'dell-poweredge-r750',
            'u_height' => 2,
        ]);

        $response = $this->actingAsAdmin()->put("/admin/device-types/{$deviceType->id}", [
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'category' => 'server',
            'u_height' => '',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, $deviceType->fresh()->u_height);
    }

    public function test_update_regenerates_a_cleared_slug(): void
    {
        $manufacturer = $this->manufacturer('Dell');
        $deviceType = DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R650',
            'slug' => 'custom-slug',
        ]);

        $response = $this->actingAsAdmin()->put("/admin/device-types/{$deviceType->id}", [
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R650',
            'category' => 'server',
            'slug' => '',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('dell-poweredge-r650', $deviceType->fresh()->slug);
    }

    public function test_update_accepts_the_device_types_own_model(): void
    {
        $manufacturer = $this->manufacturer('Dell');
        $deviceType = DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'slug' => 'dell-poweredge-r750',
        ]);

        $response = $this->actingAsAdmin()->put("/admin/device-types/{$deviceType->id}", [
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'slug' => 'dell-poweredge-r750',
            'part_number' => 'PN-UPDATED',
            'category' => 'server',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('PN-UPDATED', $deviceType->fresh()->part_number);
    }

    public function test_model_must_be_unique_per_manufacturer(): void
    {
        $dell = $this->manufacturer('Dell');
        $hpe = $this->manufacturer('Hewlett Packard');
        DeviceType::factory()->create(['manufacturer_id' => $dell->id, 'model' => 'PowerEdge R750', 'slug' => 'dell-poweredge-r750']);

        $duplicate = $this->actingAsAdmin()->post('/admin/device-types', [
            'manufacturer_id' => $dell->id,
            'model' => 'PowerEdge R750',
            'category' => 'server',
        ]);

        $duplicate->assertSessionHasErrors('model');
        $this->assertDatabaseCount('device_types', 1);

        $otherManufacturer = $this->actingAsAdmin()->post('/admin/device-types', [
            'manufacturer_id' => $hpe->id,
            'model' => 'PowerEdge R750',
            'category' => 'server',
        ]);

        $otherManufacturer->assertSessionHasNoErrors();
        $this->assertSame(2, DeviceType::count());
    }

    public function test_show_renders_with_the_linked_device(): void
    {
        $manufacturer = $this->manufacturer('Dell');
        $deviceType = DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'slug' => 'dell-poweredge-r750',
        ]);
        $asset = Device::create([
            'asset_tag' => 'AST-SHOW-1',
            'device_type_id' => $deviceType->id,
            'status' => 'in_stock',
            'warranty_expiry' => '2029-01-15',
        ]);

        $response = $this->actingAsAdmin()->get("/admin/device-types/{$deviceType->id}");

        $response->assertOk()
            ->assertSee('PowerEdge R750')
            ->assertSee('AST-SHOW-1')
            ->assertSee(route('admin.devices.show', $asset), false)
            ->assertSee('2029-01-15');
    }

    public function test_show_is_forbidden_without_the_view_permission(): void
    {
        $manufacturer = $this->manufacturer('Dell');
        $deviceType = DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'slug' => 'dell-poweredge-r750',
        ]);

        $user = User::factory()->create();

        // The seeded admin role is a superuser; remove the view gate and its
        // implied fallback (`devices.manage` implies `devices.view` in
        // PermissionMiddleware) so the refusal comes from devices.view.
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $adminRole->permissions()->detach(
            Permission::whereIn('name', ['devices.view', 'devices.manage'])->pluck('id'),
        );

        $user->assignRole('admin');

        $this->actingAs($user)
            ->get("/admin/device-types/{$deviceType->id}")
            ->assertForbidden();
    }

    public function test_index_shows_the_device_count_linking_to_the_show_page(): void
    {
        $manufacturer = $this->manufacturer('Dell');
        $deviceType = DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'slug' => 'dell-poweredge-r750',
        ]);
        Device::create(['asset_tag' => 'AST-CNT-1', 'device_type_id' => $deviceType->id]);
        Device::create(['asset_tag' => 'AST-CNT-2', 'device_type_id' => $deviceType->id]);

        $response = $this->actingAsAdmin()->get('/admin/device-types');

        $response->assertOk()
            ->assertSee(route('admin.device-types.show', $deviceType), false)
            ->assertSee('2 device(s) will lose their link', false);
    }

    public function test_destroy_deletes_the_device_type_and_nulls_the_asset_reference(): void
    {
        $manufacturer = $this->manufacturer('Dell');
        $deviceType = DeviceType::factory()->create([
            'manufacturer_id' => $manufacturer->id,
            'model' => 'PowerEdge R750',
            'slug' => 'dell-poweredge-r750',
        ]);
        $asset = Device::create([
            'asset_tag' => 'AST-0001',
            'device_type_id' => $deviceType->id,
        ]);

        $response = $this->actingAsAdmin()->delete("/admin/device-types/{$deviceType->id}");

        $response->assertRedirect(route('admin.device-types.index'));
        $this->assertDatabaseMissing('device_types', ['id' => $deviceType->id]);
        $this->assertNull($asset->fresh()->device_type_id);
    }
}
