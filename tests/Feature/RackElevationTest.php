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

/**
 * The rack show page renders a per-U elevation of the rack alongside the
 * inventory asset list. These tests assert every U height row is emitted, that
 * an occupied position shows (and links) its asset tag, that an empty position
 * shows the placeholder, and that the asset list itself names the asset by
 * asset_tag rather than the nonexistent `name` column.
 */
class RackElevationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $viewRacks = Permission::firstOrCreate(['name' => 'racks.view'], ['label' => 'View Racks']);
        $manageRacks = Permission::firstOrCreate(['name' => 'racks.manage'], ['label' => 'Manage Racks']);
        $viewDatacenters = Permission::firstOrCreate(['name' => 'datacenters.view'], ['label' => 'View Datacenters']);
        $adminRole->permissions()->syncWithoutDetaching([$viewRacks->id, $manageRacks->id, $viewDatacenters->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeRack(int $uHeight = 42, string $name = 'Rack A'): Rack
    {
        $datacenter = Datacenter::create(['name' => 'Test DC', 'code' => 'TDC']);

        return Rack::create([
            'datacenter_id' => $datacenter->id,
            'name' => $name,
            'u_height' => $uHeight,
            'status' => 'active',
        ]);
    }

    private function placeAsset(Rack $rack, string $assetTag, ?int $position = null, string $status = 'installed'): InventoryAsset
    {
        return InventoryAsset::create([
            'asset_tag' => $assetTag,
            'asset_type' => 'server',
            'rack_id' => $rack->id,
            'rack_u_position' => $position,
            'status' => $status,
        ]);
    }

    public function test_rack_show_renders_every_elevation_position(): void
    {
        $rack = $this->makeRack(42);

        $response = $this->actingAsAdmin()->get(route('admin.racks.show', $rack));

        $response->assertOk();
        $response->assertSee('data-u-position="42"', false);
        $response->assertSee('data-u-position="1"', false);
        $this->assertSame(42, substr_count($response->getContent(), 'data-u-position="'));
    }

    public function test_occupied_slot_renders_linked_asset_tag(): void
    {
        $rack = $this->makeRack(42);
        $asset = $this->placeAsset($rack, 'AST-TOP', 42);

        $response = $this->actingAsAdmin()->get(route('admin.racks.show', $rack));

        $response->assertOk();
        $response->assertSee(
            '<tr data-u-position="42"><td class="text-muted" style="width: 3.5rem;">42U</td><td><a href="'.route('admin.inventory-assets.show', $asset).'">AST-TOP</a>',
            false,
        );
    }

    public function test_empty_slot_renders_marker(): void
    {
        $rack = $this->makeRack(4);
        $this->placeAsset($rack, 'AST-BOTTOM', 1);

        $response = $this->actingAsAdmin()->get(route('admin.racks.show', $rack));

        $response->assertOk();
        $response->assertSee(
            '<tr data-u-position="2"><td class="text-muted" style="width: 3.5rem;">2U</td><td><span class="text-muted rack-slot-empty">—</span></td></tr>',
            false,
        );
    }

    public function test_asset_tag_renders_in_assets_list(): void
    {
        $rack = $this->makeRack(42);
        $asset = $this->placeAsset($rack, 'AST-LISTED', 5);

        $response = $this->actingAsAdmin()->get(route('admin.racks.show', $rack));

        $response->assertOk();
        $response->assertSee(
            '<tr><td><a href="'.route('admin.inventory-assets.show', $asset).'">AST-LISTED</a></td><td class="text-muted">5</td>',
            false,
        );
    }

    public function test_small_rack_renders_without_error(): void
    {
        $rack = $this->makeRack(4, 'Small Rack');
        $this->placeAsset($rack, 'AST-4U', 4);
        $this->placeAsset($rack, 'AST-1U', 1);

        $response = $this->actingAsAdmin()->get(route('admin.racks.show', $rack));

        $response->assertOk();
        $response->assertSee('Rack Elevation (4U)');
        $response->assertSee('data-u-position="4"', false);
        $response->assertSee('data-u-position="1"', false);
        $response->assertSee('AST-4U');
        $response->assertSee('AST-1U');
        $this->assertSame(4, substr_count($response->getContent(), 'data-u-position="'));
    }
}
