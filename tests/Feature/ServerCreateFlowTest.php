<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guards the server CREATE flow end-to-end over HTTP, mirroring ServerEditTest
 * for the edit path: the type is chosen first and locked, the form renders the
 * module's serverConfigSchema fields, and store() persists the exact values
 * the edit form later reads back. The edit form hid a real prefill mismatch
 * for a long time because nothing exercised it; create has the same exposure.
 */
final class ServerCreateFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_type_grid_lists_active_builtins(): void
    {
        $response = $this->actingAsAdminWith(['hosting.manage'])
            ->get(route('admin.servers.create-type'));

        $response->assertOk();
        $response->assertSee('Choose Server Type', false);

        foreach (['cpanel', 'plesk', 'directadmin', 'virtualizor', 'hyperv', 'proxmox'] as $slug) {
            $response->assertSee($slug, false);
        }
    }

    public function test_proxmox_is_selectable_now_that_the_driver_is_live(): void
    {
        $response = $this->actingAsAdminWith(['hosting.manage'])
            ->get(route('admin.servers.create-type'));

        $response->assertOk();

        // No parked state anywhere on the grid once the driver went live.
        $response->assertDontSee('Coming soon', false);
        $response->assertDontSee('stub', false);

        $response->assertSee(route('admin.servers.create', ['type' => 'proxmox']), false);
    }

    public function test_proxmox_create_form_renders_its_schema_fields(): void
    {
        $response = $this->actingAsAdminWith(['hosting.manage'])
            ->get(route('admin.servers.create', ['type' => 'proxmox']));

        $response->assertOk();

        $response->assertSee('value="proxmox"', false);
        foreach (['host', 'port', 'auth_type', 'api_username', 'api_password', 'ticket_username', 'verify_tls'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
    }

    public function test_store_creates_a_proxmox_server_over_http(): void
    {
        $response = $this->actingAsAdminWith(['hosting.manage'])
            ->post(route('admin.servers.store'), [
                'name' => 'pve-created-1',
                'server_type' => 'proxmox',
                'ip_address' => '10.10.0.5',
                'host' => 'pve.example.net',
                'port' => 8006,
                'auth_type' => 'token',
                'api_username' => 'root@pam!automation',
                'api_password' => 'SECRET-TOKEN',
                'status' => 'active',
                'max_accounts' => 0,
            ]);

        $response->assertRedirect();

        $server = Server::where('name', 'pve-created-1')->sole();

        $this->assertSame('proxmox', $server->server_type);
        $this->assertSame('pve.example.net', $server->ip_address);
        $this->assertSame('root@pam!automation', $server->api_username);
        $this->assertSame('SECRET-TOKEN', $server->api_password_encrypted);

        $meta = $server->connection_meta;
        $this->assertSame(8006, $meta['port'] ?? null);
        $this->assertSame('token', $meta['auth_type'] ?? null);
        // Credentials must never be persisted into the meta.
        $this->assertArrayNotHasKey('api_password', $meta);
        $this->assertArrayNotHasKey('api_key', $meta);
    }

    public function test_store_creates_a_proxmox_ticket_server(): void
    {
        $this->actingAsAdminWith(['hosting.manage'])
            ->post(route('admin.servers.store'), [
                'name' => 'pve-created-2',
                'server_type' => 'proxmox',
                'ip_address' => '10.10.0.6',
                'host' => '10.10.0.6',
                'port' => 8006,
                'auth_type' => 'ticket',
                'ticket_username' => 'root@pam',
                'api_password' => 'ROOT-PASSWORD',
                'status' => 'active',
                'max_accounts' => 0,
            ])
            ->assertRedirect();

        $server = Server::where('name', 'pve-created-2')->sole();
        $meta = $server->connection_meta;

        $this->assertSame('ticket', $meta['auth_type'] ?? null);
        $this->assertSame('root@pam', $meta['ticket_username'] ?? null);
        $this->assertSame('ROOT-PASSWORD', $server->api_password_encrypted);
    }

    public function test_create_form_locks_the_chosen_type_and_renders_schema_fields(): void
    {
        $response = $this->actingAsAdminWith(['hosting.manage'])
            ->get(route('admin.servers.create', ['type' => 'hyperv']));

        $response->assertOk();

        // Type is chosen and posted as a hidden field; there is no editable type input.
        $response->assertSee('name="server_type"', false);
        $response->assertSee('value="hyperv"', false);
        $response->assertSee('Type cannot be changed after creation.', false);

        // Hyper-V serverConfigSchema fields all render.
        foreach (['host', 'port', 'username', 'password', 'use_ssl', 'verify_tls'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
    }

    public function test_create_form_rejects_an_unknown_type(): void
    {
        $this->actingAsAdminWith(['hosting.manage'])
            ->get(route('admin.servers.create', ['type' => 'not-a-real-type']))
            ->assertNotFound();
    }

    public function test_store_creates_a_hyperv_server_over_http(): void
    {
        $response = $this->actingAsAdminWith(['hosting.manage'])
            ->post(route('admin.servers.store'), [
                'name' => 'hv-created-1',
                'server_type' => 'hyperv',
                'host' => '10.0.0.20',
                'port' => 5985,
                'username' => 'admin',
                'password' => 'Secret123!',
                'use_ssl' => '0',
                'verify_tls' => '1',
                'status' => 'active',
                'max_accounts' => 0,
            ]);

        $server = Server::sole();

        $response->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh();
        $this->assertSame('hv-created-1', $fresh->name);
        $this->assertSame('hyperv', $fresh->server_type);
        $this->assertSame('10.0.0.20', $fresh->ip_address);
        $this->assertSame('http://10.0.0.20:5985', $fresh->api_url);
        $this->assertSame('admin', $fresh->api_username);
        $this->assertSame('Secret123!', $fresh->api_password_encrypted);
        $this->assertSame('untested', $fresh->connection_status);
        $this->assertSame(5985, $fresh->connection_meta['port']);
        $this->assertFalse($fresh->connection_meta['use_ssl']);
        $this->assertTrue($fresh->connection_meta['verify_tls']);
    }

    public function test_store_creates_a_cpanel_server_over_http(): void
    {
        $response = $this->actingAsAdminWith(['hosting.manage'])
            ->post(route('admin.servers.store'), [
                'name' => 'whm-created-1',
                'server_type' => 'cpanel',
                'ip_address' => '10.0.0.30',
                'api_url' => 'https://whm.example.net:2087',
                'api_username' => 'root',
                'api_key' => 'TOKEN123',
                'status' => 'active',
                'max_accounts' => 0,
            ]);

        $server = Server::sole();

        $response->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh();
        $this->assertSame('cpanel', $fresh->server_type);
        $this->assertSame('10.0.0.30', $fresh->ip_address);
        $this->assertSame('https://whm.example.net:2087', $fresh->api_url);
        $this->assertSame('root', $fresh->api_username);
        $this->assertSame('TOKEN123', $fresh->api_key);
        $this->assertSame('untested', $fresh->connection_status);
    }

    public function test_store_rejects_an_unknown_server_type(): void
    {
        $this->actingAsAdminWith(['hosting.manage'])
            ->post(route('admin.servers.store'), [
                'name' => 'nope-1',
                'server_type' => 'not-a-real-type',
                'ip_address' => '10.0.0.40',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('server_type');

        $this->assertSame(0, Server::count());
    }

    /**
     * A Proxmox server whose node is unreachable must still render: the live
     * fetch degrades to the persisted state instead of 500-ing the page.
     */
    public function test_proxmox_show_page_renders_when_the_node_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $response = $this->actingAsAdminWith(['hosting.manage'])
            ->post(route('admin.servers.store'), [
                'name' => 'pve-show-1',
                'server_type' => 'proxmox',
                'ip_address' => '10.10.0.7',
                'host' => '10.10.0.7',
                'port' => 8006,
                'auth_type' => 'token',
                'api_username' => 'root@pam!automation',
                'api_password' => 'SECRET-TOKEN',
                'status' => 'active',
                'max_accounts' => 0,
            ]);

        $server = Server::where('name', 'pve-show-1')->sole();
        $response->assertRedirect(route('admin.servers.show', $server));

        $show = $this->actingAsAdminWith(['hosting.manage'])
            ->get(route('admin.servers.show', $server));

        $show->assertOk();
        $show->assertSee('pve-show-1', false);
    }

    private function actingAsAdminWith(array $permissionNames): self
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $ids = [];
        foreach ($permissionNames as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $role->permissions()->sync($ids);
        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
