<?php

namespace Tests\Unit;

use App\Exceptions\NoAvailableIpException;
use App\Models\HostingAccount;
use App\Models\IpAddress;
use App\Models\IpAllocationHistory;
use App\Models\IpSubnet;
use App\Services\IpAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private IpAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new IpAssignmentService;
    }

    public function test_assigns_next_available_ip(): void
    {
        $subnet = $this->makeSubnet();
        $this->makeIp($subnet, '10.1.0.1', assigned: true); // already taken — must be skipped
        $free = $this->makeIp($subnet, '10.1.0.2');
        $this->makeIp($subnet, '10.1.0.3');
        $account = $this->makeAccount();

        $ip = $this->service->assignNextAvailable($account, $subnet->id);

        $this->assertNotNull($ip);
        $this->assertSame($free->id, $ip->id, 'must lease the lowest-id available IP in scope');
        $this->assertSame(HostingAccount::class, $ip->assigned_to_type);
        $this->assertSame($account->id, $ip->assigned_to_id);

        $this->assertDatabaseHas('ip_addresses', [
            'id' => $free->id,
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);

        $history = IpAllocationHistory::where('ip_address_id', $free->id)->sole();
        $this->assertSame('assigned', $history->action);
        $this->assertNull($history->previous_assigned_to_type);
        $this->assertNull($history->previous_assigned_to_id);
        $this->assertSame(HostingAccount::class, $history->new_assigned_to_type);
        $this->assertSame($account->id, $history->new_assigned_to_id);
        $this->assertNotEmpty($history->notes);

        $snapshot = json_decode($history->ip_address_snapshot, true);
        $this->assertSame('10.1.0.2', $snapshot['ip_address']);
        $this->assertNull($snapshot['assigned_to_type'], 'snapshot must capture the row before mutation');
    }

    public function test_throws_when_subnet_empty(): void
    {
        $subnet = $this->makeSubnet();
        $this->makeIp($subnet, '10.1.0.1', assigned: true);
        $account = $this->makeAccount();

        $this->expectException(NoAvailableIpException::class);

        $this->service->assignNextAvailable($account, $subnet->id);
    }

    public function test_assign_specific_works_and_rejects_taken(): void
    {
        $subnet = $this->makeSubnet();
        $free = $this->makeIp($subnet, '10.1.0.5');
        $taken = $this->makeIp($subnet, '10.1.0.6', assigned: true);
        $account = $this->makeAccount();

        $assigned = $this->service->assignSpecific($account, $free->id);

        $this->assertSame($free->id, $assigned->id);
        $this->assertSame(HostingAccount::class, $assigned->assigned_to_type);
        $this->assertSame($account->id, $assigned->assigned_to_id);
        $this->assertDatabaseHas('ip_allocation_history', [
            'ip_address_id' => $free->id,
            'action' => 'assigned',
            'new_assigned_to_type' => HostingAccount::class,
            'new_assigned_to_id' => $account->id,
        ]);

        $this->expectException(NoAvailableIpException::class);

        $this->service->assignSpecific($account, $taken->id);
    }

    public function test_release_clears_assignment_and_writes_history(): void
    {
        $subnet = $this->makeSubnet();
        $ip = $this->makeIp($subnet, '10.1.0.9');
        $account = $this->makeAccount();
        $this->service->assignSpecific($account, $ip->id);

        $this->service->release($account, 'Account terminated');

        $fresh = $ip->fresh();
        $this->assertNull($fresh->assigned_to_type);
        $this->assertNull($fresh->assigned_to_id);

        $history = IpAllocationHistory::where('ip_address_id', $ip->id)
            ->where('action', 'released')
            ->sole();
        $this->assertSame(HostingAccount::class, $history->previous_assigned_to_type);
        $this->assertSame($account->id, $history->previous_assigned_to_id);
        $this->assertNull($history->new_assigned_to_type);
        $this->assertNull($history->new_assigned_to_id);
        $this->assertSame('Account terminated', $history->notes);

        $snapshot = json_decode($history->ip_address_snapshot, true);
        $this->assertSame(HostingAccount::class, $snapshot['assigned_to_type'], 'snapshot must capture the row before release');
    }

    public function test_sequential_assigns_return_different_ips(): void
    {
        $subnet = $this->makeSubnet();
        $this->makeIp($subnet, '10.1.0.1');
        $this->makeIp($subnet, '10.1.0.2');

        $first = $this->service->assignNextAvailable($this->makeAccount(), $subnet->id);
        $second = $this->service->assignNextAvailable($this->makeAccount(), $subnet->id);

        $this->assertNotSame($first->id, $second->id, 'each lease must re-query under lock and skip taken rows');
    }

    public function test_assigns_next_available_ip_scoped_to_network_type(): void
    {
        $public = $this->makeSubnet('public');
        $publicFree = $this->makeIp($public, '10.1.0.1');
        $private = $this->makeSubnet('private');
        $this->makeIp($private, '10.2.0.1'); // free but wrong network type — must be skipped
        $account = $this->makeAccount();

        $ip = $this->service->assignNextAvailable($account, networkType: 'public');

        $this->assertSame($publicFree->id, $ip->id, 'must lease the lowest-id available IP in a public subnet only');
        $this->assertDatabaseHas('ip_addresses', [
            'id' => $publicFree->id,
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);
    }

    public function test_assign_network_type_throws_when_matching_pool_empty(): void
    {
        $private = $this->makeSubnet('private');
        $this->makeIp($private, '10.2.0.1');
        $account = $this->makeAccount();

        $this->expectException(NoAvailableIpException::class);

        $this->service->assignNextAvailable($account, networkType: 'public');
    }

    public function test_release_clears_every_lease_an_account_holds(): void
    {
        $public = $this->makeSubnet('public');
        $private = $this->makeSubnet('private');
        $publicIp = $this->makeIp($public, '10.1.0.1');
        $privateIp = $this->makeIp($private, '10.2.0.1');
        $account = $this->makeAccount();

        $this->service->assignSpecific($account, $publicIp->id);
        $this->service->assignSpecific($account, $privateIp->id);

        $this->service->release($account, 'Account terminated');

        $this->assertNull($publicIp->fresh()->assigned_to_type);
        $this->assertNull($publicIp->fresh()->assigned_to_id);
        $this->assertNull($privateIp->fresh()->assigned_to_type);
        $this->assertNull($privateIp->fresh()->assigned_to_id);

        $this->assertSame(2, IpAllocationHistory::where('action', 'released')->count());
    }

    private function makeSubnet(string $networkType = 'private'): IpSubnet
    {
        static $sequence = 0;
        $sequence++;

        return IpSubnet::create([
            'name' => "Test Subnet {$sequence}",
            'subnet_cidr' => "10.{$sequence}.0.0/24",
            'network_type' => $networkType,
        ]);
    }

    private function makeIp(IpSubnet $subnet, string $address, bool $assigned = false): IpAddress
    {
        return IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_address' => $address,
            'assigned_to_type' => $assigned ? 'server' : null,
            'assigned_to_id' => $assigned ? 999 : null,
        ]);
    }

    private function makeAccount(): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        return HostingAccount::create([
            'customer_id' => $sequence,
            'product_id' => $sequence,
            'username' => "acct{$sequence}",
        ]);
    }
}
