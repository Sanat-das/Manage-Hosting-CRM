<?php

namespace Tests\Feature;

use App\Models\AssetRelationship;
use App\Models\HostingAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset relationships have a single permission authority: reads require
 * asset-relationships.view and writes require asset-relationships.manage. The
 * unrelated hosting.manage permission must neither expose the controls nor
 * grant the store endpoint.
 */
class AssetRelationshipPermissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a panel user whose role grants exactly the given permissions.
     *
     * A non-admin role is used deliberately: the AdminLTE package's Gate::before
     * passes every ability for admins, which would hide a permission mismatch.
     *
     * @param  array<int, string>  $permissions
     */
    private function actingAsPermissionUser(array $permissions, string $roleName = 'support'): self
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);

        foreach ($permissions as $permission) {
            $model = Permission::firstOrCreate(['name' => $permission], ['label' => $permission]);
            $role->permissions()->syncWithoutDetaching([$model->id]);
        }

        $user = User::factory()->create();
        $user->assignRole($roleName);

        return $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'parent_kind' => 'server',
            'parent_id' => 1,
            'child_kind' => 'product',
            'child_id' => 2,
            'relationship_type' => 'hosted_on',
            'label' => 'Runs web stack',
        ], $overrides);
    }

    public function test_manager_can_create_and_sees_index_controls(): void
    {
        $this->actingAsPermissionUser(['asset-relationships.view', 'asset-relationships.manage'])
            ->post('/admin/asset-relationships', $this->validPayload())
            ->assertRedirect(route('admin.asset-relationships.index'));

        $this->assertDatabaseHas('asset_relationships', [
            'parent_kind' => 'server',
            'parent_id' => 1,
            'child_kind' => 'product',
            'child_id' => 2,
            'relationship_type' => 'hosted_on',
        ]);

        $relationship = AssetRelationship::firstOrFail();

        $response = $this->get('/admin/asset-relationships');

        $response->assertOk();
        $response->assertSee(route('admin.asset-relationships.create'));
        $response->assertSee(route('admin.asset-relationships.edit', $relationship));
        $response->assertSee(route('admin.asset-relationships.destroy', $relationship));
    }

    public function test_hosting_manager_without_asset_relationship_permission_cannot_create(): void
    {
        $this->actingAsPermissionUser(['hosting.view', 'hosting.manage'])
            ->post('/admin/asset-relationships', $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('asset_relationships', 0);
    }

    public function test_hosting_edit_shows_asset_link_form_only_with_manage_permission(): void
    {
        $account = HostingAccount::create([
            'customer_id' => 1,
            'product_id' => 1,
            'username' => 'asset-perm-acct',
        ]);

        $this->actingAsPermissionUser(
            ['hosting.view', 'hosting.edit', 'asset-relationships.manage'],
            'sales',
        )->get(route('admin.hosting.edit', $account))
            ->assertOk()
            ->assertSee('Link an inventory asset');

        $this->actingAsPermissionUser(['hosting.view', 'hosting.edit'], 'marketing')
            ->get(route('admin.hosting.edit', $account))
            ->assertOk()
            ->assertDontSee('Link an inventory asset');
    }
}
