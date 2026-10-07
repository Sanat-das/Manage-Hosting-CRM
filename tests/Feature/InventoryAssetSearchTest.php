<?php

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\InventoryAsset;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryAssetSearchTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'inventory.view'], ['label' => 'View Inventory']);
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
     * Each case puts the distinctive value in one searchable column while the
     * query targets it, proving every field participates in the match.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function searchableFields(): array
    {
        return [
            'asset_tag' => ['TAG-SPOT-1', ['asset_tag' => 'TAG-SPOT-1']],
            'serial_number' => ['SN-SPOT-1', ['serial_number' => 'SN-SPOT-1']],
            'model' => ['ModelSpot-1', ['model' => 'ModelSpot-1']],
            'manufacturer' => ['MakerSpot-1', ['manufacturer' => 'MakerSpot-1']],
            'vendor' => ['VendorSpot-1', ['vendor' => 'VendorSpot-1']],
            'notes' => ['NotesSpot-1', ['notes' => 'NotesSpot-1']],
            'status' => ['maintenance', ['status' => 'maintenance']],
            'asset_type' => ['ram_module', ['asset_type' => 'ram_module']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('searchableFields')]
    public function test_matches_assets_by_any_searchable_field(string $query, array $overrides): void
    {
        $asset = $this->makeAsset($overrides);

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.inventory-assets.search', ['q' => $query]));

        $response->assertOk();
        $response->assertJsonPath('results.0.id', $asset->id);
    }

    public function test_matches_by_datacenter_and_rack_name(): void
    {
        $datacenter = Datacenter::create(['name' => 'Northwind DC', 'code' => 'NW1', 'status' => 'active']);
        $rack = Rack::create(['datacenter_id' => $datacenter->id, 'name' => 'Rockwood Rack', 'u_height' => 42, 'status' => 'active']);
        $asset = $this->makeAsset(['asset_tag' => 'AST-LOC', 'datacenter_id' => $datacenter->id, 'rack_id' => $rack->id]);
        $this->makeAsset(['asset_tag' => 'AST-ELSE']);

        $byDatacenter = $this->actingAsAdmin()
            ->getJson(route('admin.inventory-assets.search', ['q' => 'Northwind']));
        $byDatacenter->assertOk();
        $byDatacenter->assertJsonPath('results.0.id', $asset->id);

        $byRack = $this->actingAsAdmin()
            ->getJson(route('admin.inventory-assets.search', ['q' => 'Rockwood']));
        $byRack->assertOk();
        $byRack->assertJsonPath('results.0.id', $asset->id);
    }

    public function test_blank_query_returns_no_results(): void
    {
        $this->makeAsset(['asset_tag' => 'AST-ANY']);

        $this->actingAsAdmin()
            ->getJson(route('admin.inventory-assets.search'))
            ->assertOk()
            ->assertJsonPath('results', []);

        $this->actingAsAdmin()
            ->getJson(route('admin.inventory-assets.search', ['q' => '   ']))
            ->assertOk()
            ->assertJsonPath('results', []);
    }

    public function test_results_are_capped_at_twenty_and_ordered_by_asset_tag(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->makeAsset(['asset_tag' => sprintf('CAP-%03d', 25 - $i)]);
        }

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.inventory-assets.search', ['q' => 'CAP-']));

        $response->assertOk();
        $results = $response->json('results');

        $this->assertCount(20, $results);
        $this->assertStringStartsWith('CAP-001 —', $results[0]['label']);
        $this->assertStringStartsWith('CAP-020 —', $results[19]['label']);
    }

    public function test_response_has_the_expected_json_shape_and_meta(): void
    {
        $asset = $this->makeAsset([
            'asset_tag' => 'AST-SHAPE',
            'asset_type' => 'switch',
            'serial_number' => 'SN-SHAPE',
            'model' => 'Catalyst 9300',
            'manufacturer' => 'Cisco',
            'status' => 'installed',
        ]);

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.inventory-assets.search', ['q' => 'AST-SHAPE']));

        $response->assertOk();
        $response->assertJsonStructure(['results' => [['id', 'label', 'meta']]]);
        $response->assertJsonPath('results.0.id', $asset->id);
        $response->assertJsonPath('results.0.label', 'AST-SHAPE — Switch');
        $response->assertJsonPath('results.0.meta', 'Cisco Catalyst 9300 · SN SN-SHAPE · installed');
    }

    public function test_exclude_omits_the_given_asset(): void
    {
        $excluded = $this->makeAsset(['asset_tag' => 'AST-EXCL', 'model' => 'ExcludeSpot']);
        $other = $this->makeAsset(['asset_tag' => 'AST-KEEP', 'model' => 'ExcludeSpot']);

        $without = $this->actingAsAdmin()
            ->getJson(route('admin.inventory-assets.search', ['q' => 'ExcludeSpot']));
        $without->assertOk();
        $this->assertCount(2, $without->json('results'));

        $withExclude = $this->actingAsAdmin()
            ->getJson(route('admin.inventory-assets.search', ['q' => 'ExcludeSpot', 'exclude' => $excluded->id]));
        $withExclude->assertOk();
        $withExclude->assertJsonPath('results.0.id', $other->id);
        $this->assertCount(1, $withExclude->json('results'));
    }

    public function test_search_is_forbidden_without_the_inventory_view_permission(): void
    {
        $user = User::factory()->create();

        // The seeded admin role is a superuser; strip both view and manage so
        // the refusal comes from the inventory permission, not the panel door.
        // manage implies view through PermissionMiddleware, so both must go.
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $adminRole->permissions()->detach(
            Permission::whereIn('name', ['inventory.view', 'inventory.manage'])->pluck('id'),
        );

        $user->assignRole('admin');

        $this->actingAs($user)
            ->getJson(route('admin.inventory-assets.search', ['q' => 'anything']))
            ->assertForbidden();
    }
}
