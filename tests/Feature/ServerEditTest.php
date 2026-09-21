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
 * Guards the server edit form: the type is locked (hidden server_type, no
 * panel_type field), saving never re-asks for stored credentials, and a
 * server whose type is not in the active registry still saves.
 */
final class ServerEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_locks_the_server_type(): void
    {
        $server = $this->hypervServer();

        $response = $this->actingAsAdminWith(['hosting.manage'])
            ->get(route('admin.servers.edit', $server));

        $response->assertOk();
        $response->assertSee('name="server_type"', false);
        $response->assertDontSee('name="panel_type"', false);
        $response->assertSee('Hyper-V Compute');
    }

    public function test_update_saves_fields_and_keeps_blank_credentials(): void
    {
        $server = $this->hypervServer();

        $response = $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => 'hv-renamed',
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'api_url' => 'http://10.0.0.9:5985',
                'api_username' => 'admin',
                'api_key' => '',
                'max_accounts' => 5,
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
            ]);

        $response->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh();
        $this->assertSame('hv-renamed', $fresh->name);
        $this->assertSame('hyperv', $fresh->server_type);
        $this->assertSame('admin', $fresh->api_username);
        $this->assertSame('SECRET', $fresh->api_password_encrypted);
    }

    public function test_update_accepts_a_type_outside_the_active_registry(): void
    {
        $server = Server::create([
            'name' => 'legacy-generic',
            'ip_address' => '10.0.0.77',
            'server_type' => 'generic',
            'status' => 'active',
        ]);

        $response = $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => 'legacy-generic-renamed',
                'ip_address' => '10.0.0.77',
                'server_type' => 'generic',
                'status' => 'active',
                'max_accounts' => 0,
            ]);

        $response->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh();
        $this->assertSame('legacy-generic-renamed', $fresh->name);
        $this->assertSame('generic', $fresh->server_type);
    }

    private function hypervServer(): Server
    {
        return Server::create([
            'name' => 'hv-edit-1',
            'ip_address' => '10.0.0.9',
            'server_type' => 'hyperv',
            'api_url' => 'http://10.0.0.9:5985',
            'api_username' => 'admin',
            'api_password_encrypted' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => [
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => false,
                'verify_tls' => true,
            ],
        ]);
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
