<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guards ServerController::testConnection() connection_meta persist.
 *
 * A partial-success Re-test (Test-WSMan ok, info query failed → transport-only
 * meta) must deep-merge over the persisted payload instead of overwriting it,
 * so telemetry (version/totalAccounts/meta.vmCounts) survives. A failed Re-test
 * must not wipe telemetry either.
 */
final class ServerTestConnectionMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.path' => base_path('modules')]);
        app(ModuleManager::class)->reconcile();
    }

    public function test_partial_success_preserves_persisted_telemetry(): void
    {
        $server = $this->hypervServer();

        Http::fake(function ($request) {
            $body = (string) $request->body();

            if (str_contains($body, 'Identify')) {
                return Http::response($this->identifyEnvelope(), 200, [
                    'Content-Type' => 'application/soap+xml;charset=UTF-8',
                ]);
            }

            // Info query fails → testConnection succeeds with transport-only meta.
            return Http::response('Host returned no data.', 500);
        });

        $this->actingAsAdminWith(['hosting.manage'])
            ->postJson(route('admin.servers.test-connection', $server))
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $meta = $server->fresh()->connection_meta;

        $this->assertIsArray($meta);
        // Persisted telemetry survives the transport-only merge.
        $this->assertSame('10.0.20348', $meta['version']);
        $this->assertSame(3, $meta['totalAccounts']);
        $this->assertSame('hv1', $meta['meta']['hostname']);
        $this->assertSame(['total' => 3, 'running' => 1, 'stopped' => 2], $meta['meta']['vmCounts']);
        // Transport keys remain.
        $this->assertSame('10.0.0.9', $meta['host']);
        $this->assertSame(5985, $meta['port']);
        $this->assertSame('connected', $server->fresh()->connection_status);

        fwrite(STDERR, "\n[EVIDENCE test-connection-merge] partial success kept version/totalAccounts/vmCounts\n");
    }

    public function test_failed_test_preserves_persisted_telemetry(): void
    {
        $server = $this->hypervServer();

        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $this->actingAsAdminWith(['hosting.manage'])
            ->postJson(route('admin.servers.test-connection', $server))
            ->assertStatus(200)
            ->assertJsonPath('ok', false);

        $fresh = $server->fresh();
        $meta = $fresh->connection_meta;

        $this->assertIsArray($meta);
        $this->assertSame('10.0.20348', $meta['version']);
        $this->assertSame(3, $meta['totalAccounts']);
        $this->assertSame(['total' => 3, 'running' => 1, 'stopped' => 2], $meta['meta']['vmCounts']);
        $this->assertSame('failed', $fresh->connection_status);
        $this->assertNotEmpty($fresh->connection_error);

        fwrite(STDERR, "\n[EVIDENCE test-connection-fail-merge] failed Re-test kept telemetry, status=failed\n");
    }

    private function hypervServer(): Server
    {
        $module = app(ModuleManager::class)->find('hyperv');

        return Server::create([
            'name' => 'hv-meta-1',
            'ip_address' => '10.0.0.9',
            'server_type' => 'hyperv',
            'panel_type' => 'hyperv',
            'module_id' => $module?->id,
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
                'latencyMs' => 12,
                'version' => '10.0.20348',
                'totalAccounts' => 3,
                'meta' => [
                    'hostname' => 'hv1',
                    'vmCounts' => ['total' => 3, 'running' => 1, 'stopped' => 2],
                ],
            ],
        ]);
    }

    private function identifyEnvelope(): string
    {
        return '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
            . '<s:Body><IdentifyResponse xmlns="http://schemas.dmtf.org/wbem/wsman/identity/1/wsmanidentity.xsd">'
            . '<ProductVersion>OS: 10.0.20348 SP: 0.0 Stack: 3.0</ProductVersion>'
            . '</IdentifyResponse></s:Body></s:Envelope>';
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
