<?php

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpSubnetLiveUtilizationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role and the IPAM subnet permission in the test DB.
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'ip-subnets.view'], ['label' => 'View IP Subnets']);
        $manage = Permission::firstOrCreate(['name' => 'ip-subnets.manage'], ['label' => 'Manage IP Subnets']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeSubnet(int $totalAddresses = 254): IpSubnet
    {
        return IpSubnet::create([
            'name' => 'Utilization Subnet',
            'subnet_cidr' => '10.77.0.0/24',
            'network_type' => 'private',
            'total_addresses' => $totalAddresses,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeIp(IpSubnet $subnet, string $address, array $overrides = []): IpAddress
    {
        return IpAddress::create(array_merge([
            'subnet_id' => $subnet->id,
            'ip_address' => $address,
            'type' => 'available',
        ], $overrides));
    }

    public function test_show_computes_live_utilization(): void
    {
        $subnet = $this->makeSubnet(254);

        // 2 assigned (owner set) ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â both must count as used.
        $this->makeIp($subnet, '10.77.0.1', ['assigned_to_type' => 'server', 'assigned_to_id' => 1, 'type' => 'assigned']);
        $this->makeIp($subnet, '10.77.0.2', ['assigned_to_type' => 'server', 'assigned_to_id' => 2, 'type' => 'assigned']);
        // 1 reserved (no owner, type other than available) ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â counts as used.
        $this->makeIp($subnet, '10.77.0.3', ['type' => 'reserved']);
        // 1 available ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â must NOT count as used.
        $this->makeIp($subnet, '10.77.0.4', ['type' => 'available']);

        $response = $this->actingAsAdmin()->get(route('admin.ip-subnets.show', $subnet));

        $response->assertOk();
        $response->assertViewHas('utilization', function (array $utilization): bool {
            return $utilization['capacity'] === 254
                && $utilization['assigned'] === 2
                && $utilization['reserved'] === 1
                && $utilization['used'] === 3
                && $utilization['available'] === 251
                && $utilization['pct'] === 1.2;
        });

        // Dead counter columns are no longer rendered.
        $response->assertDontSee('used_addresses');
        $response->assertDontSee('reserved_count');
    }

    public function test_show_reports_zero_pct_when_capacity_is_zero(): void
    {
        $subnet = $this->makeSubnet(0);
        $this->makeIp($subnet, '10.77.0.9', ['assigned_to_type' => 'server', 'assigned_to_id' => 7, 'type' => 'assigned']);

        $response = $this->actingAsAdmin()->get(route('admin.ip-subnets.show', $subnet));

        $response->assertOk();
        $response->assertViewHas('utilization', function (array $utilization): bool {
            return $utilization['capacity'] === 0
                && $utilization['used'] === 1
                && $utilization['available'] === 0
                && $utilization['pct'] === 0;
        });
    }

    public function test_report_counts_match_show_utilization_for_the_same_fixture(): void
    {
        $subnet = $this->makeSubnet(254);
        $this->makeIp($subnet, '10.77.0.11', ['assigned_to_type' => 'server', 'assigned_to_id' => 11, 'type' => 'assigned']);
        $this->makeIp($subnet, '10.77.0.12', ['assigned_to_type' => 'server', 'assigned_to_id' => 12, 'type' => 'assigned']);
        $this->makeIp($subnet, '10.77.0.13', ['type' => 'reserved']);
        $this->makeIp($subnet, '10.77.0.14', ['type' => 'available']);

        $this->actingAsAdmin();
        $showResponse = $this->get(route('admin.ip-subnets.show', $subnet));
        $utilization = $showResponse->viewData('utilization');

        $reportsRole = Role::firstOrCreate(['name' => 'support'], ['label' => 'Support']);
        $reportsView = Permission::firstOrCreate(['name' => 'reports.view'], ['label' => 'View Reports']);
        $reportsRole->permissions()->syncWithoutDetaching([$reportsView->id]);
        $reportsUser = User::factory()->create();
        $reportsUser->assignRole('support');

        $reportResponse = $this->actingAs($reportsUser)->get('/admin/reports/inventory');
        $reportResponse->assertOk();
        $reportSubnet = collect($reportResponse->viewData('subnets'))->firstWhere('id', $subnet->id);

        $this->assertSame($utilization['assigned'], (int) $reportSubnet->assigned_count);
        $this->assertSame($utilization['reserved'], (int) $reportSubnet->reserved_count_live);
        $this->assertSame(254, (int) $reportSubnet->total_addresses);
    }
}
