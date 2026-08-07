<?php

namespace Tests\Feature;

use App\Models\HostingAccount;
use App\Models\IpAddress;
use App\Models\IpAllocationHistory;
use App\Models\IpSubnet;
use App\Models\Product;
use App\Services\HostingService;
use App\Services\IpAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class HostingActivationIpTest extends TestCase
{
    use RefreshDatabase;

    private HostingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HostingService;
    }

    public function test_pending_vps_activation_leases_next_available_ip(): void
    {
        $subnet = $this->makeSubnet();
        $this->makeIp($subnet, '10.1.0.1', assigned: true); // taken — must be skipped
        $free = $this->makeIp($subnet, '10.1.0.2');
        $account = $this->makeAccount($this->makeProduct('vps'));

        $this->service->unsuspend($account);

        $this->assertSame(HostingService::STATUS_ACTIVE, $account->fresh()->status);

        $this->assertDatabaseHas('ip_addresses', [
            'id' => $free->id,
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);

        $history = IpAllocationHistory::where('ip_address_id', $free->id)->sole();
        $this->assertSame('assigned', $history->action);
        $this->assertSame(HostingAccount::class, $history->new_assigned_to_type);
        $this->assertSame($account->id, $history->new_assigned_to_id);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'hosting.unsuspended',
            'entity_type' => 'hosting_account',
            'entity_id' => $account->id,
        ]);
    }

    public function test_pending_vps_activation_aborts_when_no_ip_available(): void
    {
        $subnet = $this->makeSubnet();
        $this->makeIp($subnet, '10.2.0.1', assigned: true); // pool exhausted
        $account = $this->makeAccount($this->makeProduct('vps'));

        try {
            $this->service->unsuspend($account);
            $this->fail('Expected RuntimeException when the IP pool is exhausted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no available IP address', $e->getMessage());
        }

        $this->assertSame(
            HostingService::STATUS_PENDING,
            $account->fresh()->status,
            'a failed lease must roll the activation back — account stays pending',
        );
        $this->assertSame(0, IpAllocationHistory::count(), 'rollback must undo the IP history row');
        $this->assertDatabaseMissing('audit_log', [
            'entity_type' => 'hosting_account',
            'entity_id' => $account->id,
        ]);
    }

    public function test_pending_shared_hosting_activation_does_not_touch_ips(): void
    {
        $subnet = $this->makeSubnet();
        $free = $this->makeIp($subnet, '10.3.0.1');
        $account = $this->makeAccount($this->makeProduct('shared_hosting'));

        $this->service->unsuspend($account);

        $this->assertSame(HostingService::STATUS_ACTIVE, $account->fresh()->status);

        $this->assertDatabaseHas('ip_addresses', [
            'id' => $free->id,
            'assigned_to_type' => null,
            'assigned_to_id' => null,
        ]);
        $this->assertSame(0, IpAllocationHistory::count());
    }

    public function test_suspended_vps_reactivation_keeps_existing_lease(): void
    {
        $subnet = $this->makeSubnet();
        $held = $this->makeIp($subnet, '10.4.0.1');
        $spare = $this->makeIp($subnet, '10.4.0.2');
        $account = $this->makeAccount($this->makeProduct('dedicated'), HostingService::STATUS_SUSPENDED);

        // The account already holds a lease from before its suspension.
        app(IpAssignmentService::class)->assignSpecific($account, $held->id);
        $historyBefore = IpAllocationHistory::count();

        $this->service->unsuspend($account);

        $this->assertSame(HostingService::STATUS_ACTIVE, $account->fresh()->status);

        $this->assertDatabaseHas('ip_addresses', [
            'id' => $held->id,
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);
        $this->assertDatabaseHas('ip_addresses', [
            'id' => $spare->id,
            'assigned_to_type' => null,
            'assigned_to_id' => null,
        ]);
        $this->assertSame(
            $historyBefore,
            IpAllocationHistory::count(),
            'suspended -> active must not consume a second IP lease',
        );
    }

    private function makeSubnet(): IpSubnet
    {
        static $sequence = 0;
        $sequence++;

        return IpSubnet::create([
            'name' => "Test Subnet {$sequence}",
            'subnet_cidr' => "10.{$sequence}.0.0/24",
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

    private function makeProduct(string $type): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::create([
            'name' => "Test Product {$sequence}",
            'type' => $type,
        ]);
    }

    private function makeAccount(Product $product, string $status = HostingService::STATUS_PENDING): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        return HostingAccount::create([
            'customer_id' => $sequence,
            'product_id' => $product->id,
            'username' => "acct{$sequence}",
            'status' => $status,
        ]);
    }
}
