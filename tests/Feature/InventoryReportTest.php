<?php

namespace Tests\Feature;

use App\Models\Datacenter;
use App\Models\InventoryAsset;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\License;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Role;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReportTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

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

    private function datacenter(string $code = 'TST1'): Datacenter
    {
        return Datacenter::create(['name' => "Datacenter {$code}", 'code' => $code, 'status' => 'active']);
    }

    private function rack(Datacenter $datacenter, string $name = 'Rack A', int $uHeight = 42): Rack
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeLicense(InventoryAsset $asset, array $overrides = []): License
    {
        return License::create(array_merge([
            'inventory_asset_id' => $asset->id,
            'license_type' => 'windows',
            'seats' => 5,
            'seats_available' => 5,
            'status' => 'active',
        ], $overrides));
    }

    public function test_inventory_report_renders_counts_for_fixtures(): void
    {
        $datacenter = $this->datacenter();
        $rack = $this->rack($datacenter);

        $this->makeAsset([
            'asset_type' => 'server',
            'status' => 'installed',
            'datacenter_id' => $datacenter->id,
            'rack_id' => $rack->id,
            'rack_u_position' => 2,
        ]);
        $this->makeAsset(['asset_type' => 'server', 'status' => 'ordered']);
        $this->makeAsset(['asset_type' => 'switch', 'status' => 'in_stock']);

        // Each license owns a distinct asset (licenses.inventory_asset_id is unique).
        $expiringAsset = $this->makeAsset(['asset_type' => 'software_license', 'status' => 'assigned']);
        $renewingAsset = $this->makeAsset(['asset_type' => 'software_license', 'status' => 'assigned']);
        $expiredAsset = $this->makeAsset(['asset_type' => 'software_license', 'status' => 'assigned']);

        $this->makeLicense($expiringAsset, ['seats_available' => 0, 'expiry_date' => now()->addDays(10)]);
        $this->makeLicense($renewingAsset, ['seats' => 1, 'seats_available' => 1, 'expiry_date' => now()->addDays(90)]);
        $this->makeLicense($expiredAsset, ['status' => 'expired', 'seats_available' => 0, 'expiry_date' => now()->subDays(5)]);

        $subnet = IpSubnet::create(['name' => 'Subnet A', 'subnet_cidr' => '10.0.0.0/24', 'total_addresses' => 256]);
        IpAddress::create(['subnet_id' => $subnet->id, 'ip_address' => '10.0.0.1', 'type' => 'available']);
        IpAddress::create(['subnet_id' => $subnet->id, 'ip_address' => '10.0.0.2', 'type' => 'available']);

        Vlan::create(['name' => 'VLAN 10', 'vlan_id' => 10]);

        $response = $this->actingAsPermissionUser(['reports.view'])->get('/admin/reports/inventory');

        $response->assertOk()
            ->assertSee('Inventory & Capacity Report')
            ->assertSee('Rack A');

        $this->assertSame(2, $response->viewData('assetsByType')['server']);
        $this->assertSame(1, $response->viewData('assetsByType')['switch']);
        $this->assertSame(3, $response->viewData('assetsByType')['software_license']);
        $this->assertSame(1, $response->viewData('assetsByStatus')['installed']);
        $this->assertSame(1, $response->viewData('assetsByStatus')['ordered']);
        $this->assertSame(1, $response->viewData('assetsByStatus')['in_stock']);
        $this->assertSame(3, $response->viewData('assetsByStatus')['assigned']);

        $this->assertSame(1, $response->viewData('racks')->first()->inventory_assets_count);

        $this->assertSame(2, $response->viewData('licensesByStatus')['active']);
        $this->assertSame(1, $response->viewData('licensesByStatus')['expired']);
        $this->assertCount(1, $response->viewData('licensesExpiring'));
        $this->assertCount(2, $response->viewData('licensesExhausted'));

        $this->assertSame(2, $response->viewData('subnets')->first()->ip_addresses_count);
        $this->assertSame(1, $response->viewData('vlanCount'));
    }

    public function test_inventory_report_returns_403_without_reports_view_permission(): void
    {
        $this->actingAsPermissionUser(['hosting.view'])->get('/admin/reports/inventory')->assertForbidden();
    }

    public function test_inventory_export_streams_asset_csv(): void
    {
        $datacenter = $this->datacenter('DCAA');
        $rack = $this->rack($datacenter, 'Rack Alpha');

        $this->makeAsset([
            'asset_tag' => 'AST-EXPORT',
            'asset_type' => 'switch',
            'status' => 'in_stock',
            'vendor' => 'Ingram',
            'datacenter_id' => $datacenter->id,
            'rack_id' => $rack->id,
            'rack_u_position' => 7,
            'purchase_date' => '2026-01-15',
            'purchase_cost' => '750.50',
            'warranty_expiry' => '2029-01-15',
        ]);

        $response = $this->actingAsPermissionUser(['reports.export'])->get('/admin/reports/export?type=inventory');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('"Asset Tag",Type,Status,Datacenter,Rack,"U Position",Vendor,"Purchase Date","Purchase Cost","Warranty Expiry"', $content);
        $this->assertStringContainsString('AST-EXPORT,switch,in_stock,"Datacenter DCAA","Rack Alpha",7,Ingram,2026-01-15,750.50,2029-01-15', $content);
    }
}
