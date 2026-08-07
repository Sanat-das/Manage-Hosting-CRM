<?php

namespace Tests\Feature;

use App\Models\HostingAccount;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostingIpCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_pull_ip_assigns_next_available_ip(): void
    {
        $subnet = $this->makeSubnet();
        $this->makeIp($subnet, '10.1.0.1', assigned: true); // taken — must be skipped
        $free = $this->makeIp($subnet, '10.1.0.2');
        $account = $this->makeAccount();

        $response = $this->actingAsAdmin()
            ->post(route('admin.hosting.pull-ip', $account));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('ip_addresses', [
            'id' => $free->id,
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);

        // Pool now exhausted — a second pull must come back with an error.
        $other = $this->makeAccount();
        $this->actingAsAdmin()
            ->post(route('admin.hosting.pull-ip', $other))
            ->assertSessionHas('error');
    }

    public function test_choose_ip_assigns_the_selected_ip(): void
    {
        $subnet = $this->makeSubnet();
        $ip = $this->makeIp($subnet, '10.1.0.5');
        $account = $this->makeAccount();

        $response = $this->actingAsAdmin()
            ->post(route('admin.hosting.choose-ip', $account), ['ip_address_id' => $ip->id]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('ip_addresses', [
            'id' => $ip->id,
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);
    }

    public function test_release_ip_clears_the_lease(): void
    {
        $subnet = $this->makeSubnet();
        $account = $this->makeAccount();
        $ip = $this->makeIp($subnet, '10.1.0.9');
        $ip->assigned_to_type = HostingAccount::class;
        $ip->assigned_to_id = $account->id;
        $ip->save();

        $response = $this->actingAsAdmin()
            ->post(route('admin.hosting.release-ip', $account), ['reason' => 'Customer request']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('ip_addresses', [
            'id' => $ip->id,
            'assigned_to_type' => null,
            'assigned_to_id' => null,
        ]);
        $this->assertDatabaseHas('ip_allocation_history', [
            'ip_address_id' => $ip->id,
            'action' => 'released',
            'notes' => 'Customer request',
        ]);
    }

    public function test_ip_actions_require_hosting_manage_permission(): void
    {
        // Admin panel role WITHOUT the hosting.manage permission.
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $user->assignRole('admin');

        $account = $this->makeAccount();
        $subnet = $this->makeSubnet();
        $ip = $this->makeIp($subnet, '10.1.0.1');

        $this->actingAs($user)->post(route('admin.hosting.pull-ip', $account))->assertForbidden();
        $this->actingAs($user)->post(route('admin.hosting.choose-ip', $account), ['ip_address_id' => $ip->id])->assertForbidden();
        $this->actingAs($user)->post(route('admin.hosting.release-ip', $account))->assertForbidden();
    }

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['hosting.view', 'hosting.manage'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
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
