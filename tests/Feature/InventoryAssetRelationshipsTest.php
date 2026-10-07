<?php

namespace Tests\Feature;

use App\Models\AssetRelationship;
use App\Models\Datacenter;
use App\Models\InventoryAsset;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAssetRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $permissions = collect([
            'inventory.view' => 'View Inventory',
            'inventory.manage' => 'Manage Inventory',
            'asset-relationships.view' => 'View Asset Relationships',
            'asset-relationships.manage' => 'Manage Asset Relationships',
        ])->map(fn ($label, $name) => Permission::firstOrCreate(['name' => $name], ['label' => $label]));

        $adminRole->permissions()->syncWithoutDetaching($permissions->pluck('id'));

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    /**
     * A non-admin role is used because the AdminLTE package's Gate::before
     * grants admins every ability, which would hide a manage-permission
     * mismatch on the asset show page.
     */
    private function actingAsViewer(): self
    {
        $role = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);

        foreach (['inventory.view' => 'View Inventory', 'asset-relationships.view' => 'View Asset Relationships'] as $name => $label) {
            $role->permissions()->syncWithoutDetaching([
                Permission::firstOrCreate(['name' => $name], ['label' => $label])->id,
            ]);
        }

        $user = User::factory()->create();
        $user->assignRole('viewer');

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
            'relationship_type' => 'contains',
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function linkPayload(InventoryAsset $parent, InventoryAsset $child, array $overrides = []): array
    {
        return array_merge([
            'parent_kind' => 'inventory_asset',
            'parent_id' => $parent->id,
            'child_kind' => 'inventory_asset',
            'child_id' => $child->id,
            'relationship_type' => 'contains',
            'label' => 'Mounted in',
        ], $overrides);
    }

    public function test_show_lists_child_and_parent_relationships_and_manage_forms(): void
    {
        $datacenter = Datacenter::create(['name' => 'Datacenter DC1', 'code' => 'DC1', 'status' => 'active']);
        $rack = Rack::create(['datacenter_id' => $datacenter->id, 'name' => 'Rack R1', 'u_height' => 42, 'status' => 'active']);

        $parent = $this->makeAsset(['asset_tag' => 'AST-PARENT']);
        $current = $this->makeAsset(['asset_tag' => 'AST-CURRENT']);
        $child = $this->makeAsset([
            'asset_tag' => 'AST-CHILD',
            'serial_number' => 'SN-CHILD-42',
            'model' => 'PowerEdge R750',
            'manufacturer' => 'Dell',
            'status' => 'maintenance',
            'datacenter_id' => $datacenter->id,
            'rack_id' => $rack->id,
            'rack_u_position' => 12,
        ]);

        $this->relationship($current->id, $child->id, ['relationship_type' => 'contains', 'label' => 'Mounted in']);
        $this->relationship($parent->id, $current->id, ['relationship_type' => 'hosted_on', 'label' => 'Host node']);

        $response = $this->actingAsAdmin()->get(route('admin.inventory-assets.show', $current));

        $response->assertOk();
        $response->assertSee('Child Assets');
        $response->assertSee('Parent Assets');
        $response->assertSee('AST-CHILD');
        $response->assertSee('AST-PARENT');
        $response->assertSee('Contains');
        $response->assertSee('Hosted on');
        $response->assertSee('Mounted in');
        $response->assertSee('Host node');
        $response->assertSee(route('admin.inventory-assets.show', $child), false);
        $response->assertSee(route('admin.inventory-assets.show', $parent), false);

        // The relationship rows carry the linked asset's full details.
        $response->assertSee('SN-CHILD-42');
        $response->assertSee('PowerEdge R750');
        $response->assertSee('Dell');
        $response->assertSee('Maintenance');
        $response->assertSee('Datacenter DC1 · Rack R1 · 12');

        // The tag selects are replaced by the searchable picker; the ids stay as
        // hidden child_id / parent_id inputs so the forms still post the same keys.
        $response->assertSee('data-asset-search-input', false);
        $response->assertSee('data-asset-search-value', false);
        $response->assertSee('name="child_id"', false);
        $response->assertSee('name="parent_id"', false);
        $response->assertSee(route('admin.inventory-assets.search', ['exclude' => $current->id]), false);

        $response->assertSee('Add child asset');
        $response->assertSee('Add parent asset');
        $response->assertSee(route('admin.asset-relationships.store'), false);
    }

    public function test_add_child_from_asset_page_creates_edge_and_redirects_back(): void
    {
        $current = $this->makeAsset(['asset_tag' => 'AST-CURRENT']);
        $child = $this->makeAsset(['asset_tag' => 'AST-CHILD']);

        $response = $this->actingAsAdmin()
            ->from(route('admin.inventory-assets.show', $current))
            ->post(route('admin.asset-relationships.store'), $this->linkPayload($current, $child));

        $response->assertRedirect(route('admin.inventory-assets.show', $current));
        $this->assertDatabaseHas('asset_relationships', [
            'parent_kind' => 'inventory_asset',
            'parent_id' => $current->id,
            'child_kind' => 'inventory_asset',
            'child_id' => $child->id,
            'relationship_type' => 'contains',
            'label' => 'Mounted in',
        ]);
    }

    public function test_self_link_is_rejected(): void
    {
        $current = $this->makeAsset(['asset_tag' => 'AST-CURRENT']);

        $response = $this->actingAsAdmin()
            ->from(route('admin.inventory-assets.show', $current))
            ->post(route('admin.asset-relationships.store'), $this->linkPayload($current, $current));

        $response->assertSessionHasErrors('child_id');
        $this->assertDatabaseCount('asset_relationships', 0);
    }

    public function test_duplicate_link_is_rejected(): void
    {
        $current = $this->makeAsset(['asset_tag' => 'AST-CURRENT']);
        $child = $this->makeAsset(['asset_tag' => 'AST-CHILD']);
        $this->relationship($current->id, $child->id, ['label' => 'Mounted in']);

        $response = $this->actingAsAdmin()
            ->from(route('admin.inventory-assets.show', $current))
            ->post(route('admin.asset-relationships.store'), $this->linkPayload($current, $child));

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('asset_relationships', 1);
    }

    public function test_remove_link_deletes_edge_and_redirects_back_to_the_asset(): void
    {
        $current = $this->makeAsset(['asset_tag' => 'AST-CURRENT']);
        $child = $this->makeAsset(['asset_tag' => 'AST-CHILD']);
        $link = $this->relationship($current->id, $child->id);

        $response = $this->actingAsAdmin()
            ->from(route('admin.inventory-assets.show', $current))
            ->delete(route('admin.asset-relationships.destroy', $link));

        $response->assertRedirect(route('admin.inventory-assets.show', $current));
        $this->assertDatabaseMissing('asset_relationships', ['id' => $link->id]);
    }

    public function test_view_only_user_sees_lists_but_no_manage_controls(): void
    {
        $current = $this->makeAsset(['asset_tag' => 'AST-CURRENT']);
        $child = $this->makeAsset(['asset_tag' => 'AST-CHILD']);
        $link = $this->relationship($current->id, $child->id, ['label' => 'Mounted in']);

        $response = $this->actingAsViewer()->get(route('admin.inventory-assets.show', $current));

        $response->assertOk();
        $response->assertSee('Child Assets');
        $response->assertSee('AST-CHILD');
        $response->assertSee('Mounted in');
        $response->assertDontSee('Add child asset');
        $response->assertDontSee('Add parent asset');
        $response->assertDontSee('remove-child-'.$link->id, false);
        $response->assertDontSee(route('admin.asset-relationships.destroy', $link), false);
    }

    public function test_backfill_migration_creates_one_edge_and_is_idempotent(): void
    {
        $parent = $this->makeAsset(['asset_tag' => 'AST-PARENT']);
        $child = $this->makeAsset(['asset_tag' => 'AST-CHILD', 'parent_asset_id' => $parent->id]);

        // A parent that no longer exists and a soft-deleted parent are both skipped.
        $this->makeAsset(['asset_tag' => 'AST-ORPHAN', 'parent_asset_id' => 999999]);
        $deletedParent = $this->makeAsset(['asset_tag' => 'AST-GONE']);
        $this->makeAsset(['asset_tag' => 'AST-ORPHAN2', 'parent_asset_id' => $deletedParent->id]);
        $deletedParent->delete();

        $migration = require database_path('migrations/2026_10_06_000001_backfill_asset_relationships_from_parent_asset_id.php');

        $migration->up();

        $this->assertDatabaseCount('asset_relationships', 1);
        $this->assertDatabaseHas('asset_relationships', [
            'parent_kind' => 'inventory_asset',
            'parent_id' => $parent->id,
            'child_kind' => 'inventory_asset',
            'child_id' => $child->id,
            'relationship_type' => 'contains',
            'label' => 'Backfilled hierarchy',
        ]);

        $migration->up();

        $this->assertDatabaseCount('asset_relationships', 1);

        $migration->down();

        $this->assertDatabaseCount('asset_relationships', 0);
    }
}
