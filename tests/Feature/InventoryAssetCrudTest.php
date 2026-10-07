<?php

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\InventoryAsset;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAssetCrudTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role and inventory permissions in the test DB.
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'inventory.view'], ['label' => 'View Inventory']);
        $manage = Permission::firstOrCreate(['name' => 'inventory.manage'], ['label' => 'Manage Inventory']);
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
            'asset_tag' => 'AST-0001',
            'asset_type' => 'server',
            'model' => 'PowerEdge R750',
        ], $overrides);
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
    private function makeAsset(array $overrides = []): InventoryAsset
    {
        $this->assetSequence++;

        return InventoryAsset::create(array_merge([
            'asset_tag' => 'AST-'.$this->assetSequence,
            'asset_type' => 'server',
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/admin/inventory-assets')->assertRedirect();
    }

    public function test_store_is_forbidden_without_manage_permission(): void
    {
        $user = User::factory()->create();

        // The seeded admin role is a superuser; remove only the route's gate so
        // the refusal comes from the inventory.manage check, not the panel door
        // (which still sees the role's other permissions).
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $adminRole->permissions()->detach(
            Permission::where('name', 'inventory.manage')->firstOrFail()->id,
        );

        $user->assignRole('admin');

        $this->actingAs($user)
            ->post('/admin/inventory-assets', $this->validPayload())
            ->assertForbidden();
    }

    public function test_admin_can_create_asset_with_vendor_and_purchase_cost(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/inventory-assets', $this->validPayload([
            'vendor' => 'Dell EMC',
            'purchase_cost' => '1499.99',
        ]));

        $response->assertRedirect(route('admin.inventory-assets.index'));
        $this->assertDatabaseHas('inventory_assets', [
            'asset_tag' => 'AST-0001',
            'vendor' => 'Dell EMC',
            'purchase_cost' => '1499.99',
        ]);
    }

    public function test_update_persists_every_editable_field(): void
    {
        $asset = $this->makeAsset();

        $response = $this->actingAsAdmin()->put("/admin/inventory-assets/{$asset->id}", [
            'asset_tag' => $asset->asset_tag,
            'asset_type' => 'switch',
            'manufacturer' => 'Cisco',
            'purchase_date' => '2026-01-15',
            'vendor' => 'Ingram Micro',
            'purchase_cost' => '750.50',
            'warranty_expiry' => '2029-01-15',
        ]);

        $response->assertRedirect(route('admin.inventory-assets.show', $asset));

        $asset->refresh();
        $this->assertSame('switch', $asset->asset_type);
        $this->assertSame('Cisco', $asset->manufacturer);
        $this->assertSame('2026-01-15', $asset->purchase_date->format('Y-m-d'));
        $this->assertSame('Ingram Micro', $asset->vendor);
        $this->assertSame('750.50', $asset->purchase_cost);
        $this->assertSame('2029-01-15', $asset->warranty_expiry->format('Y-m-d'));
    }

    public function test_u_position_greater_than_rack_height_is_rejected(): void
    {
        $rack = $this->rack($this->datacenter(), 42);

        $response = $this->actingAsAdmin()->post('/admin/inventory-assets', $this->validPayload([
            'rack_id' => $rack->id,
            'rack_u_position' => 43,
        ]));

        $response->assertSessionHasErrors([
            'rack_u_position' => 'The U position must not exceed the rack height of 42.',
        ]);
        $this->assertDatabaseCount('inventory_assets', 0);
    }

    public function test_u_position_equal_to_rack_height_is_accepted(): void
    {
        $rack = $this->rack($this->datacenter(), 42);

        $response = $this->actingAsAdmin()->post('/admin/inventory-assets', $this->validPayload([
            'rack_id' => $rack->id,
            'rack_u_position' => 42,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_assets', [
            'asset_tag' => 'AST-0001',
            'rack_id' => $rack->id,
            'rack_u_position' => 42,
        ]);
    }

    public function test_duplicate_position_in_same_rack_is_rejected(): void
    {
        $rack = $this->rack($this->datacenter());
        $this->makeAsset(['asset_tag' => 'AST-EXISTING', 'rack_id' => $rack->id, 'rack_u_position' => 5]);

        $response = $this->actingAsAdmin()->post('/admin/inventory-assets', $this->validPayload([
            'rack_id' => $rack->id,
            'rack_u_position' => 5,
        ]));

        $response->assertSessionHasErrors([
            'rack_u_position' => 'That U position is already occupied in the selected rack.',
        ]);
        $this->assertDatabaseCount('inventory_assets', 1);
    }

    public function test_same_position_in_a_different_rack_is_allowed(): void
    {
        $datacenter = $this->datacenter();
        $rackA = $this->rack($datacenter, 42, 'Rack A');
        $rackB = $this->rack($datacenter, 42, 'Rack B');
        $this->makeAsset(['asset_tag' => 'AST-EXISTING', 'rack_id' => $rackA->id, 'rack_u_position' => 5]);

        $response = $this->actingAsAdmin()->post('/admin/inventory-assets', $this->validPayload([
            'rack_id' => $rackB->id,
            'rack_u_position' => 5,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_assets', [
            'asset_tag' => 'AST-0001',
            'rack_id' => $rackB->id,
            'rack_u_position' => 5,
        ]);
    }

    public function test_updating_an_asset_with_its_own_position_is_allowed(): void
    {
        $rack = $this->rack($this->datacenter());
        $asset = $this->makeAsset(['asset_tag' => 'AST-EXISTING', 'rack_id' => $rack->id, 'rack_u_position' => 5]);

        $response = $this->actingAsAdmin()->put("/admin/inventory-assets/{$asset->id}", [
            'asset_tag' => 'AST-EXISTING',
            'model' => 'Updated model',
            'rack_id' => $rack->id,
            'rack_u_position' => 5,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_assets', [
            'id' => $asset->id,
            'model' => 'Updated model',
            'rack_u_position' => 5,
        ]);
    }

    public function test_position_without_a_rack_is_rejected(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/inventory-assets', $this->validPayload([
            'rack_u_position' => 1,
        ]));

        $response->assertSessionHasErrors([
            'rack_u_position' => 'Select a rack before setting the U position.',
        ]);
        $this->assertDatabaseCount('inventory_assets', 0);
    }

    public function test_a_soft_deleted_assets_position_is_reusable(): void
    {
        $rack = $this->rack($this->datacenter());
        $old = $this->makeAsset(['asset_tag' => 'AST-OLD', 'rack_id' => $rack->id, 'rack_u_position' => 5]);
        $old->delete();

        $response = $this->actingAsAdmin()->post('/admin/inventory-assets', $this->validPayload([
            'rack_id' => $rack->id,
            'rack_u_position' => 5,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_assets', [
            'asset_tag' => 'AST-0001',
            'rack_id' => $rack->id,
            'rack_u_position' => 5,
        ]);
    }

    public function test_index_filters_by_asset_type_status_and_datacenter(): void
    {
        $datacenterA = $this->datacenter('DCAA');
        $datacenterB = $this->datacenter('DCBB');
        $this->makeAsset(['asset_tag' => 'MATCH-SERVER', 'asset_type' => 'server', 'status' => 'in_stock', 'datacenter_id' => $datacenterA->id]);
        $this->makeAsset(['asset_tag' => 'MATCH-SWITCH', 'asset_type' => 'switch', 'status' => 'retired', 'datacenter_id' => $datacenterB->id]);

        $this->actingAsAdmin()->get('/admin/inventory-assets?asset_type=server')
            ->assertOk()
            ->assertSee('MATCH-SERVER')
            ->assertDontSee('MATCH-SWITCH');

        $this->actingAsAdmin()->get('/admin/inventory-assets?status=retired')
            ->assertOk()
            ->assertSee('MATCH-SWITCH')
            ->assertDontSee('MATCH-SERVER');

        $this->actingAsAdmin()->get('/admin/inventory-assets?datacenter_id='.$datacenterA->id)
            ->assertOk()
            ->assertSee('MATCH-SERVER')
            ->assertDontSee('MATCH-SWITCH');
    }

    public function test_destroy_soft_deletes_an_asset(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-DELETE']);

        $response = $this->actingAsAdmin()->delete("/admin/inventory-assets/{$asset->id}");

        $response->assertRedirect(route('admin.inventory-assets.index'));
        $this->assertSoftDeleted('inventory_assets', ['id' => $asset->id]);
    }

    public function test_moving_an_asset_onto_an_occupied_position_is_rejected(): void
    {
        $rack = $this->rack($this->datacenter());
        $holder = $this->makeAsset(['asset_tag' => 'AST-HOLDER', 'rack_id' => $rack->id, 'rack_u_position' => 7]);
        $mover = $this->makeAsset(['asset_tag' => 'AST-MOVER', 'rack_id' => $rack->id, 'rack_u_position' => 9]);

        $response = $this->actingAsAdmin()->put("/admin/inventory-assets/{$mover->id}", [
            'asset_tag' => 'AST-MOVER',
            'rack_id' => $rack->id,
            'rack_u_position' => 7,
        ]);

        $response->assertSessionHasErrors([
            'rack_u_position' => 'That U position is already occupied in the selected rack.',
        ]);
        $this->assertDatabaseHas('inventory_assets', ['id' => $holder->id, 'rack_u_position' => 7]);
        $this->assertDatabaseHas('inventory_assets', ['id' => $mover->id, 'rack_u_position' => 9]);
    }

    public function test_create_form_renders_vendor_and_purchase_cost_fields(): void
    {
        $this->actingAsAdmin()->get('/admin/inventory-assets/create')
            ->assertOk()
            ->assertSee('name="vendor"', false)
            ->assertSee('name="purchase_cost"', false);
    }

    public function test_edit_form_renders_every_editable_field(): void
    {
        $asset = $this->makeAsset(['manufacturer' => 'Dell', 'vendor' => 'Dell EMC']);

        $this->actingAsAdmin()->get("/admin/inventory-assets/{$asset->id}/edit")
            ->assertOk()
            ->assertSee('name="asset_type"', false)
            ->assertSee('name="manufacturer"', false)
            ->assertSee('name="purchase_date"', false)
            ->assertSee('name="vendor"', false)
            ->assertSee('name="purchase_cost"', false)
            ->assertSee('name="warranty_expiry"', false);
    }

    public function test_updating_a_legacy_asset_with_a_position_but_no_rack_is_allowed(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-LEGACY-POS', 'rack_u_position' => 12]);

        $response = $this->actingAsAdmin()->put("/admin/inventory-assets/{$asset->id}", [
            'asset_tag' => 'AST-LEGACY-POS',
            'model' => 'Legacy row updated',
            'rack_u_position' => 12,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_assets', [
            'id' => $asset->id,
            'model' => 'Legacy row updated',
            'rack_u_position' => 12,
        ]);
    }

    public function test_moving_a_legacy_position_without_a_rack_is_still_rejected(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-LEGACY-MOVE', 'rack_u_position' => 12]);

        $response = $this->actingAsAdmin()->put("/admin/inventory-assets/{$asset->id}", [
            'asset_tag' => 'AST-LEGACY-MOVE',
            'rack_u_position' => 13,
        ]);

        $response->assertSessionHasErrors('rack_u_position');
        $this->assertDatabaseHas('inventory_assets', ['id' => $asset->id, 'rack_u_position' => 12]);
    }

    public function test_bulk_status_updates_valid_ids(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-BULK', 'status' => 'in_stock']);

        $response = $this->actingAsAdmin()->post('/admin/inventory-assets/bulk-status', [
            'ids' => [$asset->id],
            'status' => 'retired',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_assets', ['id' => $asset->id, 'status' => 'retired']);
    }

    public function test_bulk_status_rejects_an_unknown_id(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-KNOWN', 'status' => 'in_stock']);

        $response = $this->actingAsAdmin()->post('/admin/inventory-assets/bulk-status', [
            'ids' => [$asset->id, 999999],
            'status' => 'retired',
        ]);

        $response->assertSessionHasErrors('ids.1');
        $this->assertDatabaseHas('inventory_assets', ['id' => $asset->id, 'status' => 'in_stock']);
    }

    public function test_bulk_status_rejects_a_soft_deleted_id(): void
    {
        $asset = $this->makeAsset(['asset_tag' => 'AST-TRASHED', 'status' => 'in_stock']);
        $asset->delete();

        $response = $this->actingAsAdmin()->post('/admin/inventory-assets/bulk-status', [
            'ids' => [$asset->id],
            'status' => 'retired',
        ]);

        $response->assertSessionHasErrors('ids.0');
        $this->assertSoftDeleted('inventory_assets', ['id' => $asset->id]);
    }

    public function test_bulk_status_rejects_a_negative_id(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/inventory-assets/bulk-status', [
            'ids' => [-1],
            'status' => 'retired',
        ]);

        $response->assertSessionHasErrors('ids.0');
    }
}
