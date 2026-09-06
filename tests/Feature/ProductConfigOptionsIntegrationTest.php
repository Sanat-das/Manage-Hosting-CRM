<?php

namespace Tests\Feature;

use App\Models\AssetRelationship;
use App\Models\Datacenter;
use App\Models\HostingAccount;
use App\Models\IpAddress;
use App\Models\IpAllocationHistory;
use App\Models\IpSubnet;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerGroupMember;
use App\Models\User;
use App\Services\HostingService;
use App\Services\IpAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end integration coverage for the IP provisioning and
 * asset-relationship components working together as an operator
 * drives them through the admin flow.
 */
class ProductConfigOptionsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_vps_provisioning_assigns_then_releases_ip_across_full_server_chain(): void
    {
        $this->actingAsAdmin();

        // Server -> ServerGroup -> ServerGroupMember -> IpSubnet -> IpAddress
        $server = Server::create([
            'name' => 'VPS Node 01',
            'ip_address' => '10.0.0.11',
        ]);
        $this->makeServerGroupMember($server, 'VPS Pool A');

        $subnet = IpSubnet::create([
            'name' => 'VPS Public Range',
            'subnet_cidr' => '10.50.0.0/24',
            'network_type' => 'public',
        ]);
        $ip = IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => '10.50.0.10',
        ]);

        $product = Product::create([
            'name' => 'VPS 2GB',
            'require_public_ip' => true,
        ]);

        $account = HostingAccount::create([
            'customer_id' => 1,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'username' => 'vps-account-01',
            'status' => HostingService::STATUS_PENDING,
        ]);

        $this->assertTrue($product->requiresIp(), 'VPS products must require an IP lease');

        // Activation leases the next available public address from the pool.
        $leased = app(IpAssignmentService::class)->assignNextAvailable($account, networkType: 'public');

        $this->assertNotNull($leased);
        $this->assertSame($ip->id, $leased->id);
        $this->assertDatabaseHas('ip_addresses', [
            'id' => $ip->id,
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);
        $this->assertDatabaseHas('ip_allocation_history', [
            'ip_address_id' => $ip->id,
            'action' => 'assigned',
            'new_assigned_to_type' => HostingAccount::class,
            'new_assigned_to_id' => $account->id,
        ]);

        // Termination / cancellation returns the address to the pool.
        app(IpAssignmentService::class)->release($account);

        $this->assertDatabaseHas('ip_addresses', [
            'id' => $ip->id,
            'assigned_to_type' => null,
            'assigned_to_id' => null,
        ]);
        $this->assertDatabaseHas('ip_allocation_history', [
            'ip_address_id' => $ip->id,
            'action' => 'released',
            'previous_assigned_to_type' => HostingAccount::class,
            'previous_assigned_to_id' => $account->id,
        ]);
    }

    public function test_dedicated_product_records_hosted_in_asset_relationship(): void
    {
        $this->actingAsAdmin();

        $server = Server::create([
            'name' => 'Dedicated Node 02',
            'ip_address' => '10.0.0.12',
        ]);
        $datacenter = Datacenter::create([
            'name' => 'Mumbai DC-1',
            'code' => 'BOM1',
        ]);
        $product = Product::create([
            'name' => 'Dedicated Xeon E-5',
            'require_public_ip' => true,
        ]);

        // A dedicated product is provisioned onto a specific server asset.
        AssetRelationship::create([
            'parent_kind' => 'product',
            'parent_id' => $product->id,
            'child_kind' => 'server',
            'child_id' => $server->id,
            'relationship_type' => 'hosted_in',
        ]);

        $this->assertTrue($product->requiresIp(), 'Dedicated products must require an IP lease');
        $this->assertDatabaseHas('asset_relationships', [
            'parent_kind' => 'product',
            'parent_id' => $product->id,
            'child_kind' => 'server',
            'child_id' => $server->id,
            'relationship_type' => 'hosted_in',
        ]);
        $this->assertDatabaseHas('datacenters', [
            'id' => $datacenter->id,
            'code' => 'BOM1',
        ]);
    }

    public function test_shared_hosting_suspend_cycle_never_touches_ip_pool(): void
    {
        $this->actingAsAdmin();

        $product = Product::create([
            'name' => 'Shared Starter',
        ]);

        $subnet = IpSubnet::create([
            'name' => 'Shared Range',
            'subnet_cidr' => '10.60.0.0/24',
        ]);
        $ip = IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => '10.60.0.1',
        ]);

        $account = HostingAccount::create([
            'customer_id' => 1,
            'product_id' => $product->id,
            'username' => 'shared-account-01',
            'status' => HostingService::STATUS_PENDING,
        ]);

        $this->assertFalse($product->requiresIp(), 'Shared hosting must never require an IP lease');

        $service = new HostingService;

        // pending -> active: no lease is taken for shared hosting.
        $service->unsuspend($account);
        $this->assertSame(HostingService::STATUS_ACTIVE, $account->fresh()->status);
        $this->assertIpPoolUntouched($ip);

        // active -> suspended: suspension never leases or releases an IP.
        $service->suspend($account, 'Invoice overdue');
        $this->assertSame(HostingService::STATUS_SUSPENDED, $account->fresh()->status);
        $this->assertIpPoolUntouched($ip);

        // suspended -> active: reactivation keeps the untouched pool state.
        $service->unsuspend($account);
        $this->assertSame(HostingService::STATUS_ACTIVE, $account->fresh()->status);
        $this->assertIpPoolUntouched($ip);
    }

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role and hosting permissions in the test DB.
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'hosting.view'], ['label' => 'View Hosting']);
        $manage = Permission::firstOrCreate(['name' => 'hosting.manage'], ['label' => 'Manage Hosting']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    /**
     * Seed the Server -> ServerGroup -> ServerGroupMember link. Uses the
     * standard create() path to prove the models' $timestamps=false fix:
     * previously ServerGroup/ServerGroupMember defaulted $timestamps = true
     * while their tables have no updated_at column, so Eloquent would throw
     * "unknown column updated_at" on save.
     */
    private function makeServerGroupMember(Server $server, string $groupName): void
    {
        $group = ServerGroup::create([
            'name' => $groupName,
            'load_balancing' => 'round_robin',
            'status' => 'active',
        ]);

        $member = ServerGroupMember::create([
            'server_group_id' => $group->id,
            'server_id' => $server->id,
        ]);
    }

    private function assertIpPoolUntouched(IpAddress $ip): void
    {
        $this->assertDatabaseHas('ip_addresses', [
            'id' => $ip->id,
            'assigned_to_type' => null,
            'assigned_to_id' => null,
        ]);
        $this->assertSame(0, IpAllocationHistory::count());
    }
}
