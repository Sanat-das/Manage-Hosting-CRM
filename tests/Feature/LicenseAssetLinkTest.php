<?php

namespace Tests\Feature;

use App\Models\InventoryAsset;
use App\Models\License;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 1:1 license ↔ inventory-asset link.
 *
 * licenses.inventory_asset_id is a NOT NULL unique FK, so the admin create
 * flow must collect and validate it. Previously the form had no field and
 * every store() attempt failed at the DB level.
 */
class LicenseAssetLinkTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role and license permissions in the test DB.
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'licenses.view'], ['label' => 'View Licenses']);
        $manage = Permission::firstOrCreate(['name' => 'licenses.manage'], ['label' => 'Manage Licenses']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSoftwareLicenseAsset(array $overrides = []): InventoryAsset
    {
        $this->assetSequence++;

        return InventoryAsset::create(array_merge([
            'asset_tag' => 'AST-SW-'.$this->assetSequence,
            'asset_type' => 'software_license',
            'status' => 'in_stock',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(InventoryAsset $asset, array $overrides = []): array
    {
        return array_merge([
            'inventory_asset_id' => $asset->id,
            'license_type' => 'cpanel',
            'license_key' => 'KEY-0001',
        ], $overrides);
    }

    public function test_store_persists_the_inventory_asset_link(): void
    {
        $asset = $this->makeSoftwareLicenseAsset();

        $response = $this->actingAsAdmin()->post('/admin/licenses', $this->validPayload($asset));

        $response->assertRedirect(route('admin.licenses.index'));
        $this->assertDatabaseHas('licenses', [
            'inventory_asset_id' => $asset->id,
            'license_type' => 'cpanel',
            'license_key' => 'KEY-0001',
        ]);
    }

    public function test_store_rejects_a_missing_inventory_asset(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/licenses', [
            'license_type' => 'cpanel',
            'license_key' => 'KEY-0001',
        ]);

        $response->assertSessionHasErrors('inventory_asset_id');
        $this->assertDatabaseCount('licenses', 0);
    }

    public function test_store_rejects_a_non_existent_inventory_asset(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/licenses', [
            'inventory_asset_id' => 999_999,
            'license_type' => 'cpanel',
            'license_key' => 'KEY-0001',
        ]);

        $response->assertSessionHasErrors('inventory_asset_id');
        $this->assertDatabaseCount('licenses', 0);
    }

    public function test_store_accepts_pending_status(): void
    {
        $asset = $this->makeSoftwareLicenseAsset();

        $response = $this->actingAsAdmin()->post('/admin/licenses', $this->validPayload($asset, [
            'status' => 'pending',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('licenses', [
            'inventory_asset_id' => $asset->id,
            'status' => 'pending',
        ]);
    }

    public function test_show_renders_a_link_to_the_linked_asset(): void
    {
        $asset = $this->makeSoftwareLicenseAsset(['asset_tag' => 'AST-SW-LINK']);
        $license = License::create([
            'inventory_asset_id' => $asset->id,
            'license_type' => 'cpanel',
            'license_key' => 'KEY-LINK',
            'status' => 'active',
        ]);

        $this->actingAsAdmin()->get("/admin/licenses/{$license->id}")
            ->assertOk()
            ->assertSee('AST-SW-LINK')
            ->assertSee(route('admin.inventory-assets.show', $asset));
    }

    public function test_store_rejects_an_asset_that_is_not_a_software_license(): void
    {
        $server = InventoryAsset::create([
            'asset_tag' => 'AST-SRV-1',
            'asset_type' => 'server',
            'status' => 'in_stock',
        ]);

        $response = $this->actingAsAdmin()->post('/admin/licenses', $this->validPayload($server));

        $response->assertSessionHasErrors('inventory_asset_id');
        $this->assertDatabaseCount('licenses', 0);
    }

    public function test_store_rejects_an_asset_already_linked_to_a_license(): void
    {
        $asset = $this->makeSoftwareLicenseAsset();
        License::create([
            'inventory_asset_id' => $asset->id,
            'license_type' => 'cpanel',
            'license_key' => 'KEY-FIRST',
            'status' => 'active',
        ]);

        $response = $this->actingAsAdmin()->post('/admin/licenses', $this->validPayload($asset, [
            'license_key' => 'KEY-SECOND',
        ]));

        $response->assertSessionHasErrors('inventory_asset_id');
        $this->assertDatabaseCount('licenses', 1);
    }

    public function test_create_form_renders_the_asset_select(): void
    {
        $this->makeSoftwareLicenseAsset(['asset_tag' => 'AST-SW-FORM']);

        $this->actingAsAdmin()->get('/admin/licenses/create')
            ->assertOk()
            ->assertSee('name="inventory_asset_id"', false)
            ->assertSee('AST-SW-FORM');
    }
}
