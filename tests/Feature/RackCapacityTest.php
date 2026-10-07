<?php

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rack occupancy is derived from the assets placed in the rack, not from the
 * retired manual `u_available` bookkeeping column. These tests assert that the
 * derived count is what each admin surface renders, and that a rack can still
 * be created through the form now that `u_available` is gone from both the
 * validation rules and the model.
 */
class RackCapacityTest extends TestCase
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

    private function makeDatacenter(): Datacenter
    {
        return Datacenter::create(['name' => 'Test DC', 'code' => 'TDC']);
    }

    private function makeRack(Datacenter $datacenter, string $name): Rack
    {
        return Rack::create([
            'datacenter_id' => $datacenter->id,
            'name' => $name,
            'u_height' => 42,
        ]);
    }

    private function placeAsset(int $rackId, string $assetTag): int
    {
        return DB::table('inventory_assets')->insertGetId([
            'asset_tag' => $assetTag,
            'asset_type' => 'server',
            'rack_id' => $rackId,
            'status' => 'installed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_rack_index_renders_per_rack_asset_counts(): void
    {
        $datacenter = $this->makeDatacenter();
        $rack = $this->makeRack($datacenter, 'Rack A');
        $this->makeRack($datacenter, 'Rack B');

        $this->placeAsset($rack->id, 'ASSET-1');
        $this->placeAsset($rack->id, 'ASSET-2');
        $this->placeAsset($rack->id, 'ASSET-3');

        $response = $this->actingAsAdmin()->get(route('admin.racks.index'));

        $response->assertOk();
        $response->assertSee('<td>3</td>', false);
        $response->assertDontSee('U Available');
    }

    public function test_rack_show_renders_asset_count(): void
    {
        $datacenter = $this->makeDatacenter();
        $rack = $this->makeRack($datacenter, 'Rack A');

        $this->placeAsset($rack->id, 'ASSET-1');
        $this->placeAsset($rack->id, 'ASSET-2');

        $response = $this->actingAsAdmin()->get(route('admin.racks.show', $rack));

        $response->assertOk();
        $response->assertSee('Assets in rack');
        $response->assertSee('<td>2</td>', false);
        $response->assertDontSee('U Available');
    }

    public function test_rack_create_without_u_available_succeeds_and_persists(): void
    {
        $datacenter = $this->makeDatacenter();

        $response = $this->actingAsAdmin()->post(route('admin.racks.store'), [
            'datacenter_id' => $datacenter->id,
            'name' => 'New Rack',
            'u_height' => 48,
            'power_capacity_watts' => 5000,
            'status' => 'active',
        ]);

        $response->assertRedirect(route('admin.racks.index'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('racks', [
            'name' => 'New Rack',
            'datacenter_id' => $datacenter->id,
            'u_height' => 48,
            'power_capacity_watts' => 5000,
        ]);
    }

    public function test_datacenter_show_renders_rack_asset_counts(): void
    {
        $datacenter = $this->makeDatacenter();
        $rack = $this->makeRack($datacenter, 'Rack A');

        $this->placeAsset($rack->id, 'ASSET-1');
        $this->placeAsset($rack->id, 'ASSET-2');

        $response = $this->actingAsAdmin()->get(route('admin.datacenters.show', $datacenter));

        $response->assertOk();
        $response->assertSee('<td>2</td>', false);
        $response->assertDontSee('U Available');
    }

    public function test_rack_update_persists_changed_datacenter_id(): void
    {
        $original = $this->makeDatacenter();
        $target = Datacenter::create(['name' => 'Target DC', 'code' => 'TGT']);
        $rack = $this->makeRack($original, 'Rack A');

        $response = $this->actingAsAdmin()->put(route('admin.racks.update', $rack), [
            'datacenter_id' => $target->id,
            'name' => 'Rack A',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('admin.racks.show', $rack));
        $this->assertDatabaseHas('racks', ['id' => $rack->id, 'datacenter_id' => $target->id]);
    }

    public function test_rack_can_be_updated_to_inactive_status(): void
    {
        $datacenter = $this->makeDatacenter();
        $rack = $this->makeRack($datacenter, 'Rack A');

        $response = $this->actingAsAdmin()->put(route('admin.racks.update', $rack), [
            'datacenter_id' => $datacenter->id,
            'name' => 'Rack A',
            'status' => 'inactive',
        ]);

        $response->assertRedirect(route('admin.racks.show', $rack));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('racks', ['id' => $rack->id, 'status' => 'inactive']);
    }

    public function test_rack_create_rejects_decommissioned_status(): void
    {
        $datacenter = $this->makeDatacenter();

        $response = $this->actingAsAdmin()->post(route('admin.racks.store'), [
            'datacenter_id' => $datacenter->id,
            'name' => 'Rack X',
            'status' => 'decommissioned',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('racks', ['name' => 'Rack X']);
    }

    public function test_rack_create_accepts_inactive_status(): void
    {
        $datacenter = $this->makeDatacenter();

        $response = $this->actingAsAdmin()->post(route('admin.racks.store'), [
            'datacenter_id' => $datacenter->id,
            'name' => 'Rack I',
            'status' => 'inactive',
        ]);

        $response->assertRedirect(route('admin.racks.index'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('racks', ['name' => 'Rack I', 'status' => 'inactive']);
    }
}
