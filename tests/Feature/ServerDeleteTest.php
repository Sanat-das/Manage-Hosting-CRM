<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ResourcePool;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerGroupMember;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Deleting a server.
 *
 * The schema has **no foreign keys** on servers(id), so a naive delete does not
 * fail — it silently orphans hosting accounts, services, panel accounts and
 * resource pools, which keep a dangling server_id. These tests pin the refusal
 * (with a count of what is in the way), the safe cleanup, and the permission.
 */
final class ServerDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function adminWith(array $perms, string $roleName = 'admin'): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);
        $ids = [];
        foreach ($perms as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $role->permissions()->sync($ids);
        $user->assignRole($roleName);

        return $user;
    }

    private function server(string $name = 'pve-1'): Server
    {
        return Server::create([
            'name' => $name,
            'ip_address' => '10.0.0.20',
            'server_type' => 'proxmox',
            'max_accounts' => 0,
            'status' => 'active',
        ]);
    }

    private function customerId(): int
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ])->id;
    }

    private function hostingAccount(Server $server): HostingAccount
    {
        return HostingAccount::create([
            'customer_id' => $this->customerId(),
            'product_id' => Product::create([
                'name' => 'Shared '.random_int(1000, 9999),
                'price' => 10,
            ])->id,
            'server_id' => $server->id,
            'domain' => 'acme.test',
            'username' => 'acme',
            'status' => 'active',
        ]);
    }

    private function service(Server $server): ServiceInstance
    {
        return ServiceInstance::create([
            'customer_id' => $this->customerId(),
            'server_id' => $server->id,
            'service_tag' => 'DEL-'.random_int(100000, 999999),
            'username' => 'acme',
            'domain' => 'acme.test',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);
    }

    // ───────────────────────────── happy path ─────────────────────────────

    public function test_an_unused_server_is_deleted_and_its_group_memberships_are_cleaned_up(): void
    {
        $server = $this->server('misconfigured-pve');

        $group = ServerGroup::create(['name' => 'Primary', 'status' => 'active']);
        ServerGroupMember::create([
            'server_group_id' => $group->id,
            'server_id' => $server->id,
            'priority' => 1,
        ]);

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->delete(route('admin.servers.destroy', $server))
            ->assertRedirect(route('admin.servers.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertSame(0, ServerGroupMember::where('server_id', $server->id)->count());
    }

    public function test_deleting_clears_the_per_server_caches(): void
    {
        $server = $this->server();

        Cache::put("proxmox:server:{$server->id}:discovered-templates", [['vmid' => '110']], 300);
        Cache::put("proxmox:server:{$server->id}:info", ['x' => 1], 300);
        Cache::put("hyperv:server:{$server->id}:vms", [['vmId' => 'a']], 300);

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->delete(route('admin.servers.destroy', $server))
            ->assertRedirect(route('admin.servers.index'));

        $this->assertNull(Cache::get("proxmox:server:{$server->id}:discovered-templates"));
        $this->assertNull(Cache::get("proxmox:server:{$server->id}:info"));
        $this->assertNull(Cache::get("hyperv:server:{$server->id}:vms"));
    }

    // ──────────────────────────── refusal guards ────────────────────────────

    public function test_a_server_with_hosting_accounts_is_not_deleted(): void
    {
        $server = $this->server();

        $this->hostingAccount($server);

        $response = $this->actingAs($this->adminWith(['hosting.manage']))
            ->delete(route('admin.servers.destroy', $server));

        $response->assertRedirect(route('admin.servers.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('servers', ['id' => $server->id]);

        // The message names what is in the way.
        $this->assertStringContainsString('1 hosting account', (string) session('error'));
    }

    public function test_a_server_with_services_is_not_deleted(): void
    {
        $server = $this->server();
        $this->service($server);

        $response = $this->actingAs($this->adminWith(['hosting.manage']))
            ->delete(route('admin.servers.destroy', $server));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('servers', ['id' => $server->id]);
        $this->assertStringContainsString('1 service', (string) session('error'));
    }

    public function test_a_server_with_panel_accounts_is_not_deleted(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'acme',
            'external_id' => '901',
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->adminWith(['hosting.manage']))
            ->delete(route('admin.servers.destroy', $server));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('servers', ['id' => $server->id]);
        $this->assertStringContainsString('1 panel account', (string) session('error'));
    }

    public function test_a_server_with_resource_pools_is_not_deleted(): void
    {
        $server = $this->server();

        ResourcePool::create([
            'server_id' => $server->id,
            'name' => 'pool-1',
            'pool_type' => 'hypervisor',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->adminWith(['hosting.manage']))
            ->delete(route('admin.servers.destroy', $server));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('servers', ['id' => $server->id]);
        $this->assertStringContainsString('1 resource pool', (string) session('error'));
    }

    public function test_every_blocker_is_reported_together(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        $this->hostingAccount($server);
        PanelAccount::create([
            'service_instance_id' => $service->id, 'server_id' => $server->id, 'panel' => 'proxmox',
            'username' => 'acme', 'external_id' => '901', 'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->adminWith(['hosting.manage']))
            ->delete(route('admin.servers.destroy', $server));

        $error = (string) session('error');

        // One pass tells the operator everything to clear, not just the first hit.
        $this->assertStringContainsString('1 hosting account', $error);
        $this->assertStringContainsString('1 service', $error);
        $this->assertStringContainsString('1 panel account', $error);
        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    public function test_the_refusal_names_the_blocking_records_so_they_can_be_found(): void
    {
        $server = $this->server();

        // A pending service has no panel account and is listed nowhere near the
        // server, so its tag is the only practical way to look it up.
        $service = $this->service($server);
        $service->update(['service_tag' => 'SVC-ORD-2026-00006']);

        $this->hostingAccount($server);

        $response = $this->actingAs($this->adminWith(['hosting.manage']))
            ->delete(route('admin.servers.destroy', $server));

        $error = (string) session('error');

        $this->assertStringContainsString('SVC-ORD-2026-00006', $error);
        $this->assertStringContainsString('acme.test', $error);
        // And it says where to look, since the record is not on the server page.
        $this->assertStringContainsString('appears under Orders', $error);
        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    public function test_the_blocker_list_is_capped_with_a_remainder(): void
    {
        $server = $this->server();

        foreach (['SVC-1', 'SVC-2', 'SVC-3', 'SVC-4', 'SVC-5'] as $tag) {
            $this->service($server)->update(['service_tag' => $tag]);
        }

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->delete(route('admin.servers.destroy', $server));

        $error = (string) session('error');

        // Count stays truthful while the sample list stays bounded.
        $this->assertStringContainsString('5 services', $error);
        $this->assertStringContainsString('SVC-1', $error);
        $this->assertStringContainsString('+2 more', $error);
        $this->assertStringNotContainsString('SVC-4', $error);
        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    // ───────────────────────────── permission ─────────────────────────────

    public function test_a_user_without_hosting_manage_cannot_delete(): void
    {
        $server = $this->server();

        // A genuine view-only staffer: the literal `admin` role is a framework
        // superuser (the AdminLTE package short-circuits the Gate for it), so a
        // permission-limited role is what actually tests the guard.
        $this->actingAs($this->adminWith(['hosting.view'], 'staff'))
            ->delete(route('admin.servers.destroy', $server))
            ->assertForbidden();

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    // ──────────────────────────────── UI ────────────────────────────────

    public function test_the_index_shows_a_delete_action_with_a_confirmation(): void
    {
        $server = $this->server('doomed-pve');

        $this->actingAs($this->adminWith(['hosting.view', 'hosting.manage']))
            ->get(route('admin.servers.index'))
            ->assertOk()
            ->assertSee('delete-server-'.$server->id, false)
            ->assertSee(route('admin.servers.destroy', $server), false)
            ->assertSee('Delete server', false);
    }

    public function test_the_show_page_has_a_danger_zone(): void
    {
        $server = $this->server();

        $this->actingAs($this->adminWith(['hosting.view', 'hosting.manage']))
            ->get(route('admin.servers.show', $server))
            ->assertOk()
            ->assertSee('Danger zone', false)
            ->assertSee('Delete server', false)
            ->assertSee(route('admin.servers.destroy', $server), false);
    }

    public function test_a_view_only_user_sees_no_delete_affordance(): void
    {
        $server = $this->server();

        $this->actingAs($this->adminWith(['hosting.view'], 'staff'))
            ->get(route('admin.servers.show', $server))
            ->assertOk()
            ->assertDontSee('Danger zone', false);
    }
}
