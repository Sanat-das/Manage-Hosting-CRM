<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // The Proxmox stub stays visibly parked.
        $response->assertSee('Coming soon', false);
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
