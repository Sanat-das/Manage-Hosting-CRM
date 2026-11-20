<?php

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\IpAllocationHistory;
use App\Models\IpSubnet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpAddressHistoryDeleteTest extends TestCase
{
    use RefreshDatabase;

    private int $subnetSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role and IPAM permissions in the test DB.
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'ip-addresses.view'], ['label' => 'View IP Addresses']);
        $manage = Permission::firstOrCreate(['name' => 'ip-addresses.manage'], ['label' => 'Manage IP Addresses']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeSubnet(): IpSubnet
    {
        $this->subnetSequence++;

        return IpSubnet::create([
            'name' => "History Subnet {$this->subnetSequence}",
            'subnet_cidr' => "10.99.{$this->subnetSequence}.0/24",
            'network_type' => 'private',
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

    private function makeHistory(IpAddress $ip, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            IpAllocationHistory::create([
                'ip_address_id' => $ip->id,
                'action' => 'assigned',
                'new_assigned_to_type' => 'server',
                'new_assigned_to_id' => 100 + $i,
                'ip_address_snapshot' => json_encode(['ip_address' => $ip->ip_address, 'seq' => $i]),
                'changed_at' => now(),
                'notes' => 'test lease',
            ]);
        }
    }

    public function test_destroy_is_blocked_when_ip_has_allocation_history(): void
    {
        $subnet = $this->makeSubnet();
        $ip = $this->makeIp($subnet, '10.99.1.10');
        $this->makeHistory($ip, 2);

        $response = $this->actingAsAdmin()->delete(route('admin.ip-addresses.destroy', $ip));

        $response->assertRedirect(route('admin.ip-addresses.show', $ip));
        $response->assertSessionHasErrors('delete');
        $this->assertStringContainsString('2 allocation history record', session('errors')->first('delete'));
        $this->assertDatabaseHas('ip_addresses', ['id' => $ip->id]);
        $this->assertSame(2, IpAllocationHistory::where('ip_address_id', $ip->id)->count());
    }

    public function test_destroy_succeeds_when_ip_has_no_history(): void
    {
        $subnet = $this->makeSubnet();
        $ip = $this->makeIp($subnet, '10.99.2.10');

        $response = $this->actingAsAdmin()->delete(route('admin.ip-addresses.destroy', $ip));

        $response->assertRedirect(route('admin.ip-addresses.index'));
        $this->assertDatabaseMissing('ip_addresses', ['id' => $ip->id]);
    }

    public function test_destroy_is_blocked_when_ip_is_assigned(): void
    {
        $subnet = $this->makeSubnet();
        $ip = $this->makeIp($subnet, '10.99.3.10', [
            'assigned_to_type' => 'server',
            'assigned_to_id' => 999,
            'type' => 'assigned',
        ]);
        // No history rows at all — the assigned guard must fire before the
        // history guard, preserving the pre-existing behavior.
        $this->assertSame(0, IpAllocationHistory::where('ip_address_id', $ip->id)->count());

        $response = $this->actingAsAdmin()->delete(route('admin.ip-addresses.destroy', $ip));

        $response->assertSessionHasErrors('delete');
        $this->assertDatabaseHas('ip_addresses', ['id' => $ip->id]);
    }
}
