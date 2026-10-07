<?php

namespace Tests\Feature;

use App\Models\AssetRelationship;
use App\Models\InventoryAsset;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTreeTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // The report is gated by asset-relationships.view, not inventory.view.
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'asset-relationships.view'], ['label' => 'View Asset Relationships']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(array $overrides = []): InventoryAsset
    {
        $this->assetSequence++;

        return InventoryAsset::create(array_merge([
            'asset_tag' => 'AST-'.$this->assetSequence,
            'asset_type' => 'server',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function relationship(int $parentId, int $childId, array $overrides = []): AssetRelationship
    {
        return AssetRelationship::create(array_merge([
            'parent_kind' => 'inventory_asset',
            'parent_id' => $parentId,
            'child_kind' => 'inventory_asset',
            'child_id' => $childId,
            'relationship_type' => 'hosted_on',
        ], $overrides));
    }

    public function test_tree_renders_parent_child_and_relationship_with_links(): void
    {
        $parent = $this->makeAsset(['asset_tag' => 'AST-PARENT']);
        $child = $this->makeAsset(['asset_tag' => 'AST-CHILD']);
        $this->relationship($parent->id, $child->id, ['label' => 'Rack mounted in']);

        $response = $this->actingAsAdmin()->get(route('admin.inventory-tree.index'));

        $response->assertOk();
        $response->assertSee('AST-PARENT');
        $response->assertSee('AST-CHILD');
        $response->assertSee('Rack mounted in');
        $response->assertSee('hosted_on');
        $response->assertSee(route('admin.inventory-assets.show', $parent), false);
        $response->assertSee(route('admin.inventory-assets.show', $child), false);
    }

    public function test_index_resolves_asset_names_as_links_instead_of_ids(): void
    {
        $parent = $this->makeAsset(['asset_tag' => 'AST-LINK-PARENT']);
        $child = $this->makeAsset(['asset_tag' => 'AST-LINK-CHILD']);
        $this->relationship($parent->id, $child->id);

        $response = $this->actingAsAdmin()->get('/admin/asset-relationships');

        $response->assertOk();
        $response->assertSee('AST-LINK-PARENT');
        $response->assertSee('AST-LINK-CHILD');
        $response->assertSee(route('admin.inventory-assets.show', $parent), false);
        $response->assertSee(route('admin.inventory-assets.show', $child), false);
        $response->assertDontSee('<code class="ms-1">#'.$parent->id.'</code>', false);
        $response->assertDontSee('<code class="ms-1">#'.$child->id.'</code>', false);
    }

    public function test_index_falls_back_for_an_unknown_kind(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-KNOWN']);
        AssetRelationship::create([
            'parent_kind' => 'widget',
            'parent_id' => 7,
            'child_kind' => 'inventory_asset',
            'child_id' => $asset->id,
            'relationship_type' => 'manages',
        ]);

        $response = $this->actingAsAdmin()->get('/admin/asset-relationships');

        $response->assertOk();
        $response->assertSee('<code class="ms-1">#7</code>', false);
        $response->assertSee('AST-KNOWN');
    }

    public function test_tree_handles_missing_assets_and_a_cycle(): void
    {
        $a = $this->makeAsset(['asset_tag' => 'AST-A']);
        $b = $this->makeAsset(['asset_tag' => 'AST-B']);

        $this->relationship($a->id, $b->id);          // normal edge
        $this->relationship($a->id, 999999);          // child asset missing
        $this->relationship(888888, $b->id);          // parent asset missing
        $this->relationship($b->id, $a->id);          // A <-> B cycle

        $response = $this->actingAsAdmin()->get(route('admin.inventory-tree.index'));

        $response->assertOk();
        $response->assertSee('AST-A');
        $response->assertSee('AST-B');
        $response->assertSee('#999999');
        $response->assertSee('already shown');
    }

    public function test_tree_is_forbidden_without_the_asset_relationship_view_permission(): void
    {
        $user = User::factory()->create();

        // The seeded admin role is a superuser; strip only the gate so the
        // refusal comes from the asset-relationships permission, not the panel
        // door (which still sees the role's other permissions). Both view and
        // manage are removed because *.manage implies *.view.
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $adminRole->permissions()->detach(
            Permission::whereIn('name', ['asset-relationships.view', 'asset-relationships.manage'])->pluck('id'),
        );

        $user->assignRole('admin');

        $this->actingAs($user)
            ->get(route('admin.inventory-tree.index'))
            ->assertForbidden();
    }
}
