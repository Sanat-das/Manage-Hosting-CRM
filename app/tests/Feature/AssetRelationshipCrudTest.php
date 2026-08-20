<?php

namespace Tests\Feature;

use App\Models\AssetRelationship;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetRelationshipCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role and hosting permissions in the test DB
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'hosting.view'], ['label' => 'View Hosting']);
        $manage = Permission::firstOrCreate(['name' => 'hosting.manage'], ['label' => 'Manage Hosting']);
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
        return array_merge([
            'parent_kind' => 'server',
            'parent_id' => 1,
            'child_kind' => 'product',
            'child_id' => 2,
            'relationship_type' => 'hosted_on',
            'label' => 'Runs web stack',
            'sort_order' => 5,
            'notes' => 'Reporting link only',
        ], $overrides);
    }

    public function test_index_requires_auth(): void
    {
        $this->get('/admin/asset-relationships')->assertRedirect();
    }

    public function test_index_loads_for_admin(): void
    {
        $this->actingAsAdmin()->get('/admin/asset-relationships')->assertStatus(200);
    }

    public function test_admin_can_create_relationship(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/asset-relationships', $this->validPayload());

        $response->assertRedirect(route('admin.asset-relationships.index'));
        $this->assertDatabaseHas('asset_relationships', [
            'parent_kind' => 'server',
            'parent_id' => 1,
            'child_kind' => 'product',
            'child_id' => 2,
            'relationship_type' => 'hosted_on',
            'label' => 'Runs web stack',
            'sort_order' => 5,
        ]);
    }

    public function test_self_relation_is_rejected(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/asset-relationships', $this->validPayload([
            'child_kind' => 'server',
            'child_id' => 1,
        ]));

        $response->assertStatus(302)->assertSessionHasErrors('child_id');
        $this->assertDatabaseCount('asset_relationships', 0);
    }

    public function test_unknown_relationship_type_is_rejected(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/asset-relationships', $this->validPayload([
            'relationship_type' => 'depends_on',
        ]));

        $response->assertStatus(302)->assertSessionHasErrors('relationship_type');
        $this->assertDatabaseCount('asset_relationships', 0);
    }

    public function test_duplicate_relationship_is_rejected(): void
    {
        AssetRelationship::create($this->validPayload());

        $response = $this->actingAsAdmin()->post('/admin/asset-relationships', $this->validPayload());

        $response->assertStatus(302)->assertSessionHasErrors();
        $this->assertDatabaseCount('asset_relationships', 1);
    }

    public function test_admin_can_update_relationship(): void
    {
        $relationship = AssetRelationship::create($this->validPayload(['label' => 'Old label']));

        $response = $this->actingAsAdmin()->put(
            "/admin/asset-relationships/{$relationship->id}",
            $this->validPayload(['label' => 'New label', 'relationship_type' => 'manages']),
        );

        $response->assertRedirect(route('admin.asset-relationships.index'));
        $this->assertDatabaseHas('asset_relationships', [
            'id' => $relationship->id,
            'label' => 'New label',
            'relationship_type' => 'manages',
        ]);
    }

    public function test_admin_can_delete_relationship(): void
    {
        $relationship = AssetRelationship::create($this->validPayload());

        $response = $this->actingAsAdmin()->delete("/admin/asset-relationships/{$relationship->id}");

        $response->assertRedirect(route('admin.asset-relationships.index'));
        $this->assertDatabaseMissing('asset_relationships', ['id' => $relationship->id]);
    }

    public function test_index_filters_by_relationship_type(): void
    {
        AssetRelationship::create($this->validPayload(['label' => 'Hosted link', 'relationship_type' => 'hosted_on']));
        AssetRelationship::create($this->validPayload(['label' => 'Managed link', 'relationship_type' => 'manages']));

        $response = $this->actingAsAdmin()->get('/admin/asset-relationships?relationship_type=manages');

        $response->assertStatus(200);
        $response->assertSee('Managed link');
        $response->assertDontSee('Hosted link');
    }
}
