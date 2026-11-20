<?php

namespace Tests\Feature;

use App\Models\DeviceType;
use App\Models\Manufacturer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManufacturerCrudTest extends TestCase
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

    public function test_index_requires_authentication(): void
    {
        $this->get('/admin/manufacturers')->assertRedirect();
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
            ->get('/admin/manufacturers/create')
            ->assertForbidden();
    }

    public function test_create_and_edit_forms_render(): void
    {
        $manufacturer = Manufacturer::factory()->create(['name' => 'Dell']);

        $this->actingAsAdmin()->get('/admin/manufacturers/create')
            ->assertOk()
            ->assertSee('name="name"', false)
            ->assertSee('name="slug"', false);

        $this->actingAsAdmin()->get("/admin/manufacturers/{$manufacturer->id}/edit")
            ->assertOk()
            ->assertSee('name="description"', false);
    }

    public function test_admin_can_create_a_manufacturer_with_an_auto_generated_slug(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/manufacturers', [
            'name' => 'Dell EMC',
            'slug' => '',
            'description' => 'Servers and storage',
        ]);

        $response->assertRedirect(route('admin.manufacturers.index'));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('manufacturers', [
            'name' => 'Dell EMC',
            'slug' => 'dell-emc',
            'description' => 'Servers and storage',
        ]);
    }

    public function test_admin_can_create_a_manufacturer_with_an_explicit_slug(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/manufacturers', [
            'name' => 'Hewlett Packard Enterprise',
            'slug' => 'hpe',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('manufacturers', ['name' => 'Hewlett Packard Enterprise', 'slug' => 'hpe']);
    }

    public function test_update_persists_fields_and_regenerates_a_cleared_slug(): void
    {
        $manufacturer = Manufacturer::factory()->create(['name' => 'Cisco', 'slug' => 'cisco']);

        $response = $this->actingAsAdmin()->put("/admin/manufacturers/{$manufacturer->id}", [
            'name' => 'Cisco Systems',
            'slug' => '',
            'description' => 'Networking hardware',
        ]);

        $response->assertRedirect(route('admin.manufacturers.index'));
        $response->assertSessionHasNoErrors();

        $manufacturer->refresh();
        $this->assertSame('Cisco Systems', $manufacturer->name);
        $this->assertSame('cisco-systems', $manufacturer->slug);
        $this->assertSame('Networking hardware', $manufacturer->description);
    }

    public function test_update_accepts_the_manufacturers_own_name_and_slug(): void
    {
        $manufacturer = Manufacturer::factory()->create(['name' => 'Supermicro', 'slug' => 'supermicro']);

        $response = $this->actingAsAdmin()->put("/admin/manufacturers/{$manufacturer->id}", [
            'name' => 'Supermicro',
            'slug' => 'supermicro',
            'description' => 'Updated description',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('Updated description', $manufacturer->fresh()->description);
    }

    public function test_duplicate_name_is_rejected(): void
    {
        Manufacturer::factory()->create(['name' => 'Supermicro']);

        $response = $this->actingAsAdmin()->post('/admin/manufacturers', [
            'name' => 'Supermicro',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('manufacturers', 1);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        Manufacturer::factory()->create(['name' => 'Supermicro', 'slug' => 'supermicro']);

        $response = $this->actingAsAdmin()->post('/admin/manufacturers', [
            'name' => 'Supermicro Incorporated',
            'slug' => 'supermicro',
        ]);

        $response->assertSessionHasErrors('slug');
        $this->assertDatabaseCount('manufacturers', 1);
    }

    public function test_destroy_deletes_the_manufacturer(): void
    {
        $manufacturer = Manufacturer::factory()->create();

        $response = $this->actingAsAdmin()->delete("/admin/manufacturers/{$manufacturer->id}");

        $response->assertRedirect(route('admin.manufacturers.index'));
        $this->assertDatabaseMissing('manufacturers', ['id' => $manufacturer->id]);
    }

    public function test_destroy_cascades_to_the_manufacturers_device_types(): void
    {
        $manufacturer = Manufacturer::factory()->create();
        DeviceType::factory()->count(2)->create(['manufacturer_id' => $manufacturer->id]);

        $this->actingAsAdmin()
            ->delete("/admin/manufacturers/{$manufacturer->id}")
            ->assertRedirect(route('admin.manufacturers.index'));

        $this->assertDatabaseCount('device_types', 0);
    }
}
