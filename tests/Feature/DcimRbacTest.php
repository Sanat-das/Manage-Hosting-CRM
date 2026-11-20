<?php

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Role;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DATACENTER/DCIM inventory RBAC: racks, datacenters and vlans.
 *
 * Reads are gated by <area>.view, writes by <area>.manage. A view-only user
 * must reach the index but be turned away from store/update/destroy.
 */
class DcimRbacTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsViewOnly(string $area): self
    {
        $user = User::factory()->create();

        // The migration-seeded admin role already holds every permission on a
        // fresh install, so gate through a dedicated viewer role instead: sync
        // exactly <area>.view and nothing else.
        $viewerRole = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $view = Permission::firstOrCreate(['name' => "{$area}.view"], ['label' => "View {$area}"]);
        $viewerRole->permissions()->syncWithoutDetaching([$view->id]);

        $user->assignRole('viewer');

        return $this->actingAs($user);
    }

    private function makeDatacenter(): Datacenter
    {
        return Datacenter::create(['name' => 'Test DC', 'code' => 'TST1']);
    }

    private function makeRack(): Rack
    {
        $datacenter = $this->makeDatacenter();

        return Rack::create(['datacenter_id' => $datacenter->id, 'name' => 'Rack A']);
    }

    private function makeVlan(): Vlan
    {
        return Vlan::create(['name' => 'Test VLAN', 'vlan_id' => 100]);
    }

    public function test_datacenters_index_is_reachable_with_view_only(): void
    {
        $this->actingAsViewOnly('datacenters')
            ->get('/admin/datacenters')
            ->assertOk();
    }

    public function test_datacenters_store_is_forbidden_without_manage(): void
    {
        $this->actingAsViewOnly('datacenters')
            ->post('/admin/datacenters', ['name' => 'Nope DC', 'code' => 'NOPE'])
            ->assertForbidden();

        $this->assertDatabaseMissing('datacenters', ['code' => 'NOPE']);
    }

    public function test_datacenters_update_is_forbidden_without_manage(): void
    {
        $datacenter = $this->makeDatacenter();

        $this->actingAsViewOnly('datacenters')
            ->put("/admin/datacenters/{$datacenter->id}", ['name' => 'Renamed DC'])
            ->assertForbidden();

        $this->assertSame('Test DC', $datacenter->fresh()->name);
    }

    public function test_datacenters_destroy_is_forbidden_without_manage(): void
    {
        $datacenter = $this->makeDatacenter();

        $this->actingAsViewOnly('datacenters')
            ->delete("/admin/datacenters/{$datacenter->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('datacenters', ['id' => $datacenter->id]);
    }

    public function test_racks_index_is_reachable_with_view_only(): void
    {
        $this->actingAsViewOnly('racks')
            ->get('/admin/racks')
            ->assertOk();
    }

    public function test_racks_store_is_forbidden_without_manage(): void
    {
        $datacenter = $this->makeDatacenter();

        $this->actingAsViewOnly('racks')
            ->post('/admin/racks', ['datacenter_id' => $datacenter->id, 'name' => 'Nope Rack'])
            ->assertForbidden();

        $this->assertDatabaseMissing('racks', ['name' => 'Nope Rack']);
    }

    public function test_racks_update_is_forbidden_without_manage(): void
    {
        $rack = $this->makeRack();

        $this->actingAsViewOnly('racks')
            ->put("/admin/racks/{$rack->id}", ['name' => 'Renamed Rack'])
            ->assertForbidden();

        $this->assertSame('Rack A', $rack->fresh()->name);
    }

    public function test_racks_destroy_is_forbidden_without_manage(): void
    {
        $rack = $this->makeRack();

        $this->actingAsViewOnly('racks')
            ->delete("/admin/racks/{$rack->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('racks', ['id' => $rack->id]);
    }

    public function test_vlans_index_is_reachable_with_view_only(): void
    {
        $this->actingAsViewOnly('vlans')
            ->get('/admin/vlans')
            ->assertOk();
    }

    public function test_vlans_store_is_forbidden_without_manage(): void
    {
        $this->actingAsViewOnly('vlans')
            ->post('/admin/vlans', ['name' => 'Nope VLAN', 'vlan_id' => 999])
            ->assertForbidden();

        $this->assertDatabaseMissing('vlans', ['vlan_id' => 999]);
    }

    public function test_vlans_update_is_forbidden_without_manage(): void
    {
        $vlan = $this->makeVlan();

        $this->actingAsViewOnly('vlans')
            ->put("/admin/vlans/{$vlan->id}", ['name' => 'Renamed VLAN'])
            ->assertForbidden();

        $this->assertSame('Test VLAN', $vlan->fresh()->name);
    }

    public function test_vlans_destroy_is_forbidden_without_manage(): void
    {
        $vlan = $this->makeVlan();

        $this->actingAsViewOnly('vlans')
            ->delete("/admin/vlans/{$vlan->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('vlans', ['id' => $vlan->id]);
    }
}
