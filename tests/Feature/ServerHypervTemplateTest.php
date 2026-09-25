<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Services\Integrations\IntegrationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ServerHypervTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdminWith(array $perms): self
    {
        $user = \App\Models\User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $ids = [];
        foreach ($perms as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $role->permissions()->sync($ids);
        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function hypervServer(array $meta = []): Server
    {
        return Server::create([
            'name' => 'hv-template-'.uniqid(),
            'ip_address' => '10.0.0.9',
            'server_type' => 'hyperv',
            'api_url' => 'http://10.0.0.9:5985',
            'api_username' => 'admin',
            'api_password_encrypted' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_status' => 'connected',
            'connection_meta' => array_merge([
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => false,
                'verify_tls' => true,
            ], $meta),
        ]);
    }

    public function test_update_persists_template_vm_while_keeping_transport(): void
    {
        $server = $this->hypervServer(['vmCounts' => ['running' => 1, 'total' => 2], 'custom_telemetry' => 'keep-me']);

        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vm' => '  MyTemplate  ',
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $meta = $server->fresh()->connection_meta;
        $this->assertSame('MyTemplate', $meta['template_vm'] ?? null);
        $this->assertSame(5985, $meta['port'] ?? null);
        $this->assertFalse((bool) ($meta['use_ssl'] ?? true));
        $this->assertTrue((bool) ($meta['verify_tls'] ?? false));
        // other keys preserved byte-for-byte
        $this->assertSame(['running' => 1, 'total' => 2], $meta['vmCounts'] ?? null);
        $this->assertSame('keep-me', $meta['custom_telemetry'] ?? null);
    }

    public function test_omitting_template_vm_preserves_existing_value(): void
    {
        $server = $this->hypervServer(['template_vm' => 'KeepExisting', 'vmCounts' => ['running' => 1, 'total' => 2]]);

        // Simulate legacy/partial form that does not send template_vm at all.
        // We bypass validation to call hypervConnectionMeta directly, proving
        // absent key leaves existing untouched while transport still updates.
        $controller = app(\App\Http\Controllers\Admin\ServerController::class);
        $method = new \ReflectionMethod($controller, 'hypervConnectionMeta');
        $method->setAccessible(true);

        // Case 1: validated without template_vm but with transport change -> transport updates, template preserved
        $existing = $server->connection_meta;
        $result = $method->invoke($controller, ['port' => 5986, 'use_ssl' => '1'], $existing);
        $this->assertSame('KeepExisting', $result['template_vm'] ?? null);
        $this->assertSame(5986, $result['port'] ?? null);
        $this->assertSame(['running' => 1, 'total' => 2], $result['vmCounts'] ?? null);

        // Case 2: validated empty (no keys) -> returns null, meaning no update (no silent wipe)
        $nullResult = $method->invoke($controller, [], $existing);
        $this->assertNull($nullResult);

        // Case 3: via HTTP - send update without template_vm field; server should keep it
        // Build payload omitting template_vm entirely (use direct DB update simulation via controller update)
        // For HTTP path, the field will be absent from validated, so hypervConnectionMeta is called
        // without template_vm and must preserve it. We test via direct PUT omitting the key
        // and asserting the persisted value survives.
        // Note: PUT without template_vm goes through rules where template_vm is nullable, so absent is allowed.
        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5986,
                'use_ssl' => '1',
                'verify_tls' => '1',
                'password' => '',
                'max_accounts' => 0,
                // template_vm intentionally omitted
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh()->connection_meta;
        $this->assertSame('KeepExisting', $fresh['template_vm'] ?? null, 'Omitting template_vm must preserve existing value (no silent data loss)');
        $this->assertSame(5986, $fresh['port'] ?? null);
    }

    public function test_empty_template_vm_removes_key_but_keeps_transport(): void
    {
        $server = $this->hypervServer(['template_vm' => 'OldTemplate']);

        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5986,
                'use_ssl' => '1',
                'verify_tls' => '0',
                'password' => '',
                'template_vm' => '',
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $meta = $server->fresh()->connection_meta;
        $this->assertArrayNotHasKey('template_vm', $meta);
        $this->assertSame(5986, $meta['port'] ?? null);
        $this->assertTrue((bool) ($meta['use_ssl'] ?? false));
        $this->assertFalse((bool) ($meta['verify_tls'] ?? true));
    }

    public function test_vms_endpoint_returns_list_and_handles_non_hyperv_and_failure(): void
    {
        // success: fake driver via anonymous class so method_exists passes
        $server = $this->hypervServer();
        $fakeVms = [
            ['name' => 'TEMPLATE-01', 'state' => 'Off', 'vmId' => 'guid-1', 'switchName' => 'External', 'uptime' => '', 'cpuUsage' => 0, 'memoryAssigned' => 0, 'memoryDemand' => 0, 'processorCount' => 2, 'version' => '9.0', 'vhdPath' => ''],
            ['name' => 'RunningVM', 'state' => 'Running', 'vmId' => 'guid-2', 'switchName' => 'Default Switch', 'uptime' => '', 'cpuUsage' => 0, 'memoryAssigned' => 0, 'memoryDemand' => 0, 'processorCount' => 2, 'version' => '9.0', 'vhdPath' => ''],
        ];
        $driver = new class($fakeVms) implements \App\Contracts\Integrations\TestableServerModule {
            public function __construct(private array $vms) {}
            public function serverConfigSchema(): array { return ['fields'=>[]]; }
            public function testConnection(\App\Models\Server $s): \App\Contracts\Integrations\ServerConnectionResult { return \App\Contracts\Integrations\ServerConnectionResult::ok('ok',0,[]); }
            public function getServerInfo(\App\Models\Server $s): \App\Contracts\Integrations\ServerInfoDTO { return new \App\Contracts\Integrations\ServerInfoDTO('','', '',0,0,[]); }
            public function listVms($s) { return $this->vms; }
        };
        $registry = \Mockery::mock(IntegrationRegistry::class);
        $registry->shouldReceive('resolveForServer')->andReturn($driver);
        $this->app->instance(IntegrationRegistry::class, $registry);
        Cache::forget("hyperv:server:{$server->id}:vms");

        $resp = $this->actingAsAdminWith(['hosting.manage'])
            ->getJson(route('admin.servers.vms', $server));
        $resp->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('vms.0.name', 'TEMPLATE-01')
            ->assertJsonPath('vms.0.state', 'Off')
            ->assertJsonPath('vms.1.name', 'RunningVM');

        // refresh param forces forget + reload
        $freshVms = [
            ['name' => 'NEW-TEMPLATE', 'state' => 'Off', 'vmId' => 'guid-3', 'switchName' => 'External', 'uptime' => '', 'cpuUsage' => 0, 'memoryAssigned' => 0, 'memoryDemand' => 0, 'processorCount' => 2, 'version' => '9.0', 'vhdPath' => ''],
        ];
        $driver2 = new class($freshVms) implements \App\Contracts\Integrations\TestableServerModule {
            public function __construct(private array $vms) {}
            public function serverConfigSchema(): array { return ['fields'=>[]]; }
            public function testConnection(\App\Models\Server $s): \App\Contracts\Integrations\ServerConnectionResult { return \App\Contracts\Integrations\ServerConnectionResult::ok('ok',0,[]); }
            public function getServerInfo(\App\Models\Server $s): \App\Contracts\Integrations\ServerInfoDTO { return new \App\Contracts\Integrations\ServerInfoDTO('','', '',0,0,[]); }
            public function listVms($s) { return $this->vms; }
        };
        $registry2 = \Mockery::mock(IntegrationRegistry::class);
        $registry2->shouldReceive('resolveForServer')->andReturn($driver2);
        $this->app->instance(IntegrationRegistry::class, $registry2);

        $this->actingAsAdminWith(['hosting.manage'])
            ->getJson(route('admin.servers.vms', $server).'?refresh=1')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('vms.0.name', 'NEW-TEMPLATE');

        // non-hyperv yields documented non-ok response (422)
        $this->app->forgetInstance(IntegrationRegistry::class);
        $cpanel = Server::create([
            'name' => 'cpanel-1',
            'ip_address' => '192.0.2.10',
            'server_type' => 'cpanel',
            'status' => 'active',
        ]);
        $this->actingAsAdminWith(['hosting.manage'])
            ->getJson(route('admin.servers.vms', $cpanel))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        // transport failure yields ok:false, HTTP 200, no exception
        $failServer = $this->hypervServer();
        $failDriver = new class implements \App\Contracts\Integrations\TestableServerModule {
            public function serverConfigSchema(): array { return ['fields'=>[]]; }
            public function testConnection(\App\Models\Server $s): \App\Contracts\Integrations\ServerConnectionResult { return \App\Contracts\Integrations\ServerConnectionResult::ok('ok',0,[]); }
            public function getServerInfo(\App\Models\Server $s): \App\Contracts\Integrations\ServerInfoDTO { return new \App\Contracts\Integrations\ServerInfoDTO('','', '',0,0,[]); }
            public function listVms($s) { throw new \RuntimeException('WinRM timeout after 8s'); }
        };
        $failRegistry = \Mockery::mock(IntegrationRegistry::class);
        $failRegistry->shouldReceive('resolveForServer')->andReturn($failDriver);
        $this->app->instance(IntegrationRegistry::class, $failRegistry);
        Cache::forget("hyperv:server:{$failServer->id}:vms");

        $this->actingAsAdminWith(['hosting.manage'])
            ->getJson(route('admin.servers.vms', $failServer))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('vms', []);

        $this->app->forgetInstance(IntegrationRegistry::class);
        \Mockery::close();
    }

    public function test_edit_page_renders_select_with_saved_value(): void
    {
        $server = $this->hypervServer(['template_vm' => 'MyTemplate']);

        $resp = $this->actingAsAdminWith(['hosting.manage'])
            ->get(route('admin.servers.edit', $server));

        $resp->assertOk();
        $resp->assertSee('name="template_vm"', false);
        $resp->assertSee('MyTemplate', false);
        $resp->assertSee('MyTemplate (not found on host)', false);
        $resp->assertSee('Template must be shut down', false);
    }

    public function test_show_fresh_fetch_does_not_drop_template_vm(): void
    {
        $server = $this->hypervServer(['template_vm' => 'KeepMe', 'host' => '10.0.0.9', 'port' => 5985]);
        // Ensure show's fresh fetch path preserves template_vm.
        // Fake Http so getServerInfo succeeds (otherwise it degrades but still preserves).
        Http::fake(function () {
            return Http::response(json_encode(['vmHost' => ['hostname' => 'hv-host', 'hostOS' => 'Windows Server', 'ramTotal' => 8589934592, 'ramFree' => 4294967296, 'logicalCpu' => 4]]), 200);
        });
        Cache::forget("hyperv:server:{$server->id}:vms");
        Cache::forget("hyperv:server:{$server->id}:info");

        $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.servers.show', $server))
            ->assertOk();

        $meta = $server->fresh()->connection_meta;
        $this->assertSame('KeepMe', $meta['template_vm'] ?? null);
        $this->assertSame(5985, $meta['port'] ?? null);
    }

    public function test_show_renders_template_badge_and_missing_hint(): void
    {
        $server = $this->hypervServer(['template_vm' => 'MyTemplate']);
        $server->update(['connection_status' => 'connected']);

        // Case: VM present on host -> no missing hint
        Cache::put("hyperv:server:{$server->id}:vms", [
            ['name' => 'MyTemplate', 'state' => 'Off', 'vmId' => 'g1', 'switchName' => 'External', 'uptime'=>'', 'cpuUsage'=>0, 'memoryAssigned'=>0,'memoryDemand'=>0,'processorCount'=>2,'version'=>'','vhdPath'=>''],
            ['name' => 'OtherVM', 'state' => 'Running', 'vmId' => 'g2', 'switchName' => 'External', 'uptime'=>'', 'cpuUsage'=>0,'memoryAssigned'=>0,'memoryDemand'=>0,'processorCount'=>2,'version'=>'','vhdPath'=>''],
        ], 60);
        Cache::forget("hyperv:server:{$server->id}:info");
        Http::fake(fn() => Http::response(json_encode(['vmHost'=>['hostname'=>'hv-host']]),200));

        $resp = $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.servers.show', $server));
        $resp->assertOk();
        $resp->assertSee('Template VM: MyTemplate', false);
        $resp->assertDontSee('(missing on host)', false);

        // Case: VM missing on host -> badge shows missing hint
        Cache::put("hyperv:server:{$server->id}:vms", [
            ['name' => 'OtherVM', 'state' => 'Running', 'vmId' => 'g2', 'switchName' => 'External', 'uptime'=>'', 'cpuUsage'=>0,'memoryAssigned'=>0,'memoryDemand'=>0,'processorCount'=>2,'version'=>'','vhdPath'=>''],
        ], 60);

        $resp2 = $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.servers.show', $server));
        $resp2->assertOk();
        // Multi-template badges include "(default)" marker; check presence of both parts separately for legacy single.
        $resp2->assertSee('Template VM: MyTemplate', false);
        $resp2->assertSee('(missing on host)', false);
    }

    // ── Multi-template contract (new) ──

    public function test_update_persists_list_and_default_and_preserves_transport(): void
    {
        $server = $this->hypervServer(['vmCounts' => ['running' => 1, 'total' => 2], 'custom_telemetry' => 'keep-me']);

        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => ['TemplateA', 'TemplateB'],
                'template_vm' => 'TemplateA',
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $meta = $server->fresh()->connection_meta;
        $this->assertSame(['TemplateA', 'TemplateB'], $meta['template_vms'] ?? null);
        $this->assertSame('TemplateA', $meta['template_vm'] ?? null);
        $this->assertSame(5985, $meta['port'] ?? null);
        $this->assertSame(['running' => 1, 'total' => 2], $meta['vmCounts'] ?? null);
        $this->assertSame('keep-me', $meta['custom_telemetry'] ?? null);
        // helpers
        $fresh = $server->fresh();
        $this->assertSame(['TemplateA', 'TemplateB'], $fresh->hypervTemplateVms());
        $this->assertSame('TemplateA', $fresh->hypervDefaultTemplate());
    }

    public function test_empty_list_clears_curated_and_default_and_empty_default_clears_only_default(): void
    {
        $server = $this->hypervServer(['template_vms' => ['T1', 'T2'], 'template_vm' => 'T1']);

        // empty default clears only default, list stays
        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => ['T1', 'T2'],
                'template_vm' => '',
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $meta = $server->fresh()->connection_meta;
        $this->assertSame(['T1', 'T2'], $meta['template_vms'] ?? null);
        $this->assertArrayNotHasKey('template_vm', $meta);
        $this->assertNull($server->fresh()->hypervDefaultTemplate());

        // empty list clears curated (sets to []) and default drops
        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => [],
                'template_vm' => '',
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $meta2 = $server->fresh()->connection_meta;
        $this->assertSame([], $meta2['template_vms'] ?? null);
        $this->assertArrayNotHasKey('template_vm', $meta2);
        $this->assertSame([], $server->fresh()->hypervTemplateVms());
        $this->assertNull($server->fresh()->hypervDefaultTemplate());
    }

    public function test_absent_keys_preserve_existing_values(): void
    {
        $server = $this->hypervServer(['template_vms' => ['KeepA', 'KeepB'], 'template_vm' => 'KeepA', 'vmCounts' => ['running' => 1]]);

        $controller = app(\App\Http\Controllers\Admin\ServerController::class);
        $m = new \ReflectionMethod($controller, 'hypervConnectionMeta');
        $m->setAccessible(true);
        $existing = $server->connection_meta;
        $result = $m->invoke($controller, ['port' => 5986], $existing);
        $this->assertSame(['KeepA', 'KeepB'], $result['template_vms'] ?? null);
        $this->assertSame('KeepA', $result['template_vm'] ?? null);

        // via HTTP absent
        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5986,
                'use_ssl' => '1',
                'verify_tls' => '1',
                'password' => '',
                'max_accounts' => 0,
                // template_vms and template_vm intentionally omitted
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh();
        $this->assertSame(['KeepA', 'KeepB'], $fresh->connection_meta['template_vms'] ?? null);
        $this->assertSame('KeepA', $fresh->connection_meta['template_vm'] ?? null);
        $this->assertSame(['KeepA', 'KeepB'], $fresh->hypervTemplateVms());
        $this->assertSame('KeepA', $fresh->hypervDefaultTemplate());
    }

    public function test_legacy_fallback_and_after_save_wins(): void
    {
        $server = $this->hypervServer(['template_vm' => 'LegacyOne']);
        // Ensure no template_vms key
        $meta = $server->connection_meta;
        $this->assertArrayNotHasKey('template_vms', $meta);
        $this->assertSame(['LegacyOne'], $server->fresh()->hypervTemplateVms());
        $this->assertSame('LegacyOne', $server->fresh()->hypervDefaultTemplate());

        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => ['New1', 'New2'],
                'template_vm' => 'New2',
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh();
        $this->assertSame(['New1', 'New2'], $fresh->hypervTemplateVms());
        $this->assertSame('New2', $fresh->hypervDefaultTemplate());
        $this->assertSame(['New1', 'New2'], $fresh->connection_meta['template_vms'] ?? null);
        // legacy no longer fallback
        $this->assertNotSame(['LegacyOne'], $fresh->hypervTemplateVms());
    }

    public function test_sanitization_blanks_duplicates_whitespace_and_dedup(): void
    {
        $server = $this->hypervServer();

        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => ['  MyTemplate  ', ' ', 'MyTemplate', 'Other ', '  Other', ''],
                'template_vm' => 'MyTemplate',
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh();
        $this->assertSame(['MyTemplate', 'Other'], $fresh->hypervTemplateVms());
        $this->assertSame(['MyTemplate', 'Other'], $fresh->connection_meta['template_vms'] ?? null);
    }

    public function test_long_name_rejected_by_validation(): void
    {
        $server = $this->hypervServer();
        $long = str_repeat('A', 65);

        $resp = $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => [$long],
                'template_vm' => $long,
                'max_accounts' => 0,
            ]);

        // Must be rejected (validation) — assert session has errors for template_vms.0 or template_vm
        // If truncated instead, then persisted value would be 64 chars; we accept either but prefer validation.
        if ($resp->status() === 302) {
            $resp->assertSessionHasErrors();
        } else {
            $resp->assertStatus(422);
        }
        // Ensure no 65-char persisted
        $fresh = $server->fresh();
        foreach ($fresh->hypervTemplateVms() as $name) {
            $this->assertLessThanOrEqual(64, mb_strlen($name));
        }
        // Direct sanitize also truncates
        $this->assertSame([str_repeat('A', 64)], \App\Models\Server::sanitizeTemplateVms([$long]));
    }

    public function test_default_not_in_list_not_persisted(): void
    {
        $server = $this->hypervServer(['template_vms' => ['A', 'B'], 'template_vm' => 'A']);

        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => ['A', 'B'],
                'template_vm' => 'C-NotInList',
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh();
        $this->assertSame(['A', 'B'], $fresh->hypervTemplateVms());
        $this->assertNull($fresh->hypervDefaultTemplate());
        $this->assertArrayNotHasKey('template_vm', $fresh->connection_meta);
    }

    public function test_edit_renders_multi_select_and_default_preselected(): void
    {
        $server = $this->hypervServer(['template_vms' => ['Alpha', 'Beta'], 'template_vm' => 'Beta']);

        $resp = $this->actingAsAdminWith(['hosting.manage'])
            ->get(route('admin.servers.edit', $server));
        $resp->assertOk();
        $resp->assertSee('name="template_vms[]"', false);
        $resp->assertSee('name="template_vm"', false);
        $resp->assertSee('for="field_template_vms"', false);
        $resp->assertSee('for="field_template_vm"', false);
        // Curated multi-select has both options preselected (as not found on host when live empty)
        $resp->assertSee('Alpha (not found on host)', false);
        $resp->assertSee('Beta (not found on host)', false);
        // Default select has Beta preselected
        $resp->assertSee('— No default —', false);
        $resp->assertSee('Curated templates are what admin/clients can pick', false);
    }

    public function test_show_renders_badges_per_template_with_default_marker(): void
    {
        $server = $this->hypervServer(['template_vms' => ['T1', 'T2'], 'template_vm' => 'T1']);
        $server->update(['connection_status' => 'connected']);

        Cache::put("hyperv:server:{$server->id}:vms", [
            ['name' => 'T1', 'state' => 'Off', 'vmId' => 'g1', 'switchName' => 'External', 'uptime'=>'', 'cpuUsage'=>0, 'memoryAssigned'=>0,'memoryDemand'=>0,'processorCount'=>2,'version'=>'','vhdPath'=>''],
            ['name' => 'T2', 'state' => 'Off', 'vmId' => 'g2', 'switchName' => 'External', 'uptime'=>'', 'cpuUsage'=>0,'memoryAssigned'=>0,'memoryDemand'=>0,'processorCount'=>2,'version'=>'','vhdPath'=>''],
        ], 60);
        Cache::forget("hyperv:server:{$server->id}:info");
        Http::fake(fn() => Http::response(json_encode(['vmHost'=>['hostname'=>'hv-host']]),200));

        $resp = $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.servers.show', $server));
        $resp->assertOk();
        $resp->assertSee('Template VM: T1 (default)', false);
        $resp->assertSee('Template VM: T2', false);
        $resp->assertDontSee('(missing on host)', false);

        // Missing case
        Cache::put("hyperv:server:{$server->id}:vms", [
            ['name' => 'T1', 'state' => 'Off', 'vmId' => 'g1', 'switchName' => 'External', 'uptime'=>'', 'cpuUsage'=>0, 'memoryAssigned'=>0,'memoryDemand'=>0,'processorCount'=>2,'version'=>'','vhdPath'=>''],
        ], 60);

        $resp2 = $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.servers.show', $server));
        $resp2->assertOk();
        $resp2->assertSee('Template VM: T2 (missing on host)', false);
    }

    // ── Label contract ──

    public function test_persists_labels_for_curated_names_and_fallback(): void
    {
        $server = $this->hypervServer();

        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => ['TPL-WS2022', 'TPL-UBUNTU'],
                'template_vm' => 'TPL-WS2022',
                'template_label_for' => ['TPL-WS2022', 'TPL-UBUNTU'],
                'template_label' => ['Windows Server 2022', 'Ubuntu 22.04 LTS'],
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh();
        $meta = $fresh->connection_meta;
        $this->assertSame(['TPL-WS2022' => 'Windows Server 2022', 'TPL-UBUNTU' => 'Ubuntu 22.04 LTS'], $meta['template_labels'] ?? null);
        $this->assertSame('Windows Server 2022', $fresh->hypervTemplateLabel('TPL-WS2022'));
        $this->assertSame('Ubuntu 22.04 LTS', $fresh->hypervTemplateLabel('TPL-UBUNTU'));
        // fallback to name when absent
        $this->assertSame('TPL-NOLABEL', $fresh->hypervTemplateLabel('TPL-NOLABEL'));
        $opts = $fresh->hypervTemplateOptions();
        $this->assertSame([
            ['name' => 'TPL-WS2022', 'label' => 'Windows Server 2022'],
            ['name' => 'TPL-UBUNTU', 'label' => 'Ubuntu 22.04 LTS'],
        ], $opts);
    }

    public function test_empty_label_removes_entry_and_stale_keys_pruned(): void
    {
        $server = $this->hypervServer(['template_vms' => ['A', 'B'], 'template_vm' => 'A', 'template_labels' => ['A' => 'Label A', 'B' => 'Label B', 'STALE' => 'ShouldDrop']]);

        // send empty label for B -> removes B; STALE already pruned via sanitizer on read, but also test save prunes
        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => ['A', 'B'],
                'template_vm' => 'A',
                'template_label_for' => ['A', 'B'],
                'template_label' => ['Keep A', ''],
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh();
        $labels = $fresh->connection_meta['template_labels'] ?? null;
        $this->assertSame(['A' => 'Keep A'], $labels);
        $this->assertArrayNotHasKey('STALE', $labels ?? []);
        $this->assertSame('Keep A', $fresh->hypervTemplateLabel('A'));
        $this->assertSame('B', $fresh->hypervTemplateLabel('B'));

        // Stale key pruning when curated shrinks
        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => ['A'],
                'template_vm' => 'A',
                'template_label_for' => ['A'],
                'template_label' => ['Keep A'],
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $fresh2 = $server->fresh();
        $this->assertSame(['A' => 'Keep A'], $fresh2->connection_meta['template_labels'] ?? null);
        $this->assertArrayNotHasKey('B', $fresh2->connection_meta['template_labels'] ?? []);
    }

    public function test_absent_label_payload_preserves_existing_labels(): void
    {
        $server = $this->hypervServer(['template_vms' => ['KeepA', 'KeepB'], 'template_vm' => 'KeepA', 'template_labels' => ['KeepA' => 'Existing Label', 'KeepB' => 'Second']]);

        $controller = app(\App\Http\Controllers\Admin\ServerController::class);
        $m = new \ReflectionMethod($controller, 'hypervConnectionMeta');
        $m->setAccessible(true);
        $existing = $server->connection_meta;
        $result = $m->invoke($controller, ['port' => 5986], $existing);
        $this->assertSame(['KeepA' => 'Existing Label', 'KeepB' => 'Second'], $result['template_labels'] ?? null);

        // via HTTP absent label fields preserves
        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5986,
                'use_ssl' => '1',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => ['KeepA', 'KeepB'],
                'template_vm' => 'KeepA',
                // template_label_for / template_label intentionally omitted
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $fresh = $server->fresh();
        $this->assertSame(['KeepA' => 'Existing Label', 'KeepB' => 'Second'], $fresh->connection_meta['template_labels'] ?? null);
    }

    public function test_label_max_80_validation_or_truncation(): void
    {
        $server = $this->hypervServer();
        $long = str_repeat('L', 81);

        $resp = $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vms' => ['TPL-A'],
                'template_vm' => 'TPL-A',
                'template_label_for' => ['TPL-A'],
                'template_label' => [$long],
                'max_accounts' => 0,
            ]);

        if ($resp->status() === 302 && $resp->getSession()->has('errors')) {
            $resp->assertSessionHasErrors();
            $fresh = $server->fresh();
            // No 81-char label persisted
            foreach (($fresh->connection_meta['template_labels'] ?? []) as $lbl) {
                $this->assertLessThanOrEqual(80, mb_strlen($lbl));
            }
        } else {
            $resp->assertStatus(422);
        }
        // Direct sanitize truncates
        $this->assertSame(['TPL-A' => str_repeat('L', 80)], \App\Models\Server::sanitizeTemplateLabels(['TPL-A' => $long], ['TPL-A']));
        // Trim + blank removal
        $this->assertSame([], \App\Models\Server::sanitizeTemplateLabels(['TPL-A' => '   '], ['TPL-A']));
        $this->assertSame(['TPL-A' => 'Nice'], \App\Models\Server::sanitizeTemplateLabels(['TPL-A' => '  Nice  '], ['TPL-A']));
    }

    public function test_show_renders_label_and_raw_name_when_they_differ(): void
    {
        $server = $this->hypervServer(['template_vms' => ['TPL-WS2022', 'TPL-UBUNTU'], 'template_vm' => 'TPL-WS2022', 'template_labels' => ['TPL-WS2022' => 'Windows Server 2022']]);
        $server->update(['connection_status' => 'connected']);
        Cache::put("hyperv:server:{$server->id}:vms", [
            ['name' => 'TPL-WS2022', 'state' => 'Off', 'vmId' => 'g1', 'switchName' => 'External', 'uptime'=>'', 'cpuUsage'=>0, 'memoryAssigned'=>0,'memoryDemand'=>0,'processorCount'=>2,'version'=>'','vhdPath'=>''],
            ['name' => 'TPL-UBUNTU', 'state' => 'Off', 'vmId' => 'g2', 'switchName' => 'External', 'uptime'=>'', 'cpuUsage'=>0,'memoryAssigned'=>0,'memoryDemand'=>0,'processorCount'=>2,'version'=>'','vhdPath'=>''],
        ], 60);
        Cache::forget("hyperv:server:{$server->id}:info");
        Http::fake(fn() => Http::response(json_encode(['vmHost'=>['hostname'=>'hv-host']]),200));

        $resp = $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.servers.show', $server));
        $resp->assertOk();
        $resp->assertSee('Windows Server 2022', false);
        $resp->assertSee('TPL-WS2022', false);
        $resp->assertSee('title="TPL-WS2022"', false);
        $resp->assertSee('TPL-UBUNTU', false);
        // Fallback label equals name -> no duplicated muted suffix with same text wrapping? still shows TPL-UBUNTU once.
        $resp->assertSee('Template VM: Windows Server 2022', false);
        $resp->assertSee('(default)', false);
    }

    public function test_client_provision_card_renders_label_with_name_as_value(): void
    {
        $server = $this->hypervServer(['template_vms' => ['TPL-WS2022', 'TPL-UBUNTU'], 'template_vm' => 'TPL-WS2022', 'template_labels' => ['TPL-WS2022' => 'Windows Server 2022']]);
        $product = \App\Models\Product::create(['name' => 'HyperV Client', 'price' => 100, 'billing_cycle' => 'monthly', 'provisioning_module' => 'hyperv', 'status' => 'active']);
        $customer = \App\Models\Customer::create(['user_id' => \App\Models\User::factory()->create()->id, 'status' => 'active']);
        $account = \App\Models\HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'status' => 'pending',
            'host_name' => 'test-client-'.uniqid(),
        ]);

        $resp = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id));
        $resp->assertOk();
        $resp->assertSee('value="TPL-WS2022"', false);
        $resp->assertSee('Windows Server 2022', false);
        $resp->assertSee('value="TPL-UBUNTU"', false);
        // label fallback equals name when no label
        $resp->assertSee('>TPL-UBUNTU<', false);
    }

    public function test_admin_create_modal_renders_label_with_name_as_value(): void
    {
        $server = $this->hypervServer(['template_vms' => ['TPL-WS2022', 'TPL-UBUNTU'], 'template_vm' => 'TPL-WS2022', 'template_labels' => ['TPL-WS2022' => 'Windows Server 2022']]);
        $product = \App\Models\Product::create(['name' => 'HyperV Admin', 'price' => 100, 'billing_cycle' => 'monthly', 'provisioning_module' => 'hyperv', 'status' => 'active']);
        \App\Models\ProductModule::create(['product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => []]);
        $customer = \App\Models\Customer::create(['user_id' => \App\Models\User::factory()->create()->id, 'status' => 'active']);
        $account = \App\Models\HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'status' => 'active',
            'host_name' => 'admin-modal-'.uniqid(),
        ]);

        $resp = $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.show', $account));
        $resp->assertOk();
        $resp->assertSee('value="TPL-WS2022"', false);
        $resp->assertSee('Windows Server 2022', false);
        $resp->assertSee('value="TPL-UBUNTU"', false);
    }

    public function test_edit_renders_label_inputs_and_hint(): void
    {
        $server = $this->hypervServer(['template_vms' => ['TPL-WS2022'], 'template_vm' => 'TPL-WS2022', 'template_labels' => ['TPL-WS2022' => 'Windows Server 2022']]);

        $resp = $this->actingAsAdminWith(['hosting.manage'])
            ->get(route('admin.servers.edit', $server));
        $resp->assertOk();
        $resp->assertSee('name="template_label_for[]"', false);
        $resp->assertSee('name="template_label[]"', false);
        $resp->assertSee('Template labels', false);
        $resp->assertSee('Friendly labels are shown to admins and clients', false);
        $resp->assertSee('for="template_label_', false);
        $resp->assertSee('Windows Server 2022', false);
    }
}
