<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The IP typeahead endpoint backs the device form's IP picker. It is gated by
 * the permission its data belongs to (ip-addresses.view), not devices.view.
 */
class IpSearchPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsWithPermissions(array $permissions): self
    {
        $user = User::factory()->create();

        // The migration-seeded admin role already holds every permission on a
        // fresh install, so gate through a dedicated viewer role instead: sync
        // exactly the permissions the test needs and nothing else.
        $viewerRole = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $ids = [];
        foreach ($permissions as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $viewerRole->permissions()->syncWithoutDetaching($ids);

        $user->assignRole('viewer');

        return $this->actingAs($user);
    }

    public function test_devices_view_only_user_is_forbidden_from_ip_search(): void
    {
        $this->actingAsWithPermissions(['devices.view'])
            ->getJson('/admin/ip-addresses/search?query=x')
            ->assertForbidden();
    }

    public function test_devices_manage_only_user_is_forbidden_from_ip_search(): void
    {
        $this->actingAsWithPermissions(['devices.view', 'devices.manage'])
            ->getJson('/admin/ip-addresses/search?query=x')
            ->assertForbidden();
    }

    public function test_ip_addresses_view_user_gets_json_from_ip_search(): void
    {
        $this->actingAsWithPermissions(['ip-addresses.view'])
            ->getJson('/admin/ip-addresses/search?query=x')
            ->assertOk()
            ->assertJsonStructure(['results']);
    }
}
