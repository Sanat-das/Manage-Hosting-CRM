<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HostingAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\ViewModels\Admin\ServerDetailViewModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Todo 14 evidence: Essential show-page coverage.
 *
 * HTTP GET is used ONLY for panel-type servers (no module driver resolves,
 * so the controller degrades to persisted connection_meta with no live
 * fetch). Hyper-V rendering is covered via direct dumb-blade renders to
 * avoid WinRM dials — the blade must stay presentation-only. Pins: Host /
 * Uptime / Available / Consumption cards per type, no-raw-dump, drift badge
 * over HTTP, SNMP-absent empty state.
 */
final class ServerEssentialShowTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function actingAsAdmin(): void
    {
        $user = User::factory()->create();
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'hosting.view'], ['label' => 'View Hosting']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id]);
        $user->assignRole('admin');
        $this->actingAs($user);
    }

    private function makeServer(array $overrides = []): Server
    {
        ++self::$seq;

        return Server::create(array_merge([
            'name' => 'Essential Show Srv '.self::$seq,
            'ip_address' => '192.0.2.'.(50 + self::$seq),
            'server_type' => 'cpanel',
            'status' => 'active',
            'connection_status' => 'connected',
        ], $overrides));
    }

    private function makeLocalAccounts(Server $server, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            HostingAccount::create([
                'customer_id' => 3000 + self::$seq * 10 + $i,
                'product_id' => 4000 + $i,
                'server_id' => $server->id,
                'username' => 'showuser'.self::$seq.'_'.$i,
                'status' => 'active',
            ]);
        }
    }

    public function test_show_renders_four_essential_cards_without_raw_dump(): void
    {
        $this->actingAsAdmin();
        $server = $this->makeServer([
            'connection_meta' => [
                'hostname' => 'panel-host.example.com',
                'version' => 'cPanel 128.0',
                'totalAccounts' => 2,
            ],
        ]);
        $this->makeLocalAccounts($server, 2);

        $response = $this->get(route('admin.servers.show', $server));

        $response->assertStatus(200);
        $response->assertSee('Essential Information', false);
        $response->assertSee('data-card="host"', false);
        $response->assertSee('data-card="uptime"', false);
        $response->assertSee('data-card="available"', false);
        $response->assertSee('data-card="consumption"', false);
        $response->assertSee('panel-host.example.com');
        // No raw dump of the persisted payload may leak into the page.
        $response->assertDontSee('connection_meta', false);
        $response->assertDontSee('print_r', false);

        fwrite(STDERR, "\n[EVIDENCE show-cards] 4 cards rendered, hostname visible, no raw dump\n");
    }

    public function test_show_drift_badge_over_http_on_mismatch(): void
    {
        $this->actingAsAdmin();
        $server = $this->makeServer(['connection_meta' => ['totalAccounts' => 5]]);
        $this->makeLocalAccounts($server, 3);

        $response = $this->get(route('admin.servers.show', $server));

        $response->assertStatus(200);
        $response->assertSee('data-census="drift-badge"', false);
        $response->assertSee('Remote differs from ledger', false);

        fwrite(STDERR, "\n[EVIDENCE show-drift] remote=5 local=3 badge rendered over HTTP\n");
    }

    public function test_show_snmp_absent_renders_frozen_uptime_empty_state(): void
    {
        $this->actingAsAdmin();
        $server = $this->makeServer(['connection_meta' => ['hostname' => 'no-snmp.example.com']]);

        $response = $this->get(route('admin.servers.show', $server));

        $response->assertStatus(200);
        $response->assertSee('No data yet — Re-test', false);

        fwrite(STDERR, "\n[EVIDENCE show-snmp-absent] frozen uptime empty state rendered, no SNMP dial\n");
    }

    public function test_hyperv_panels_render_single_transport_strip(): void
    {
        $server = $this->makeServer([
            'server_type' => 'hyperv',
            'api_url' => 'https://hv-transport.example.com:5986',
            'connection_meta' => [
                'host' => 'hv-transport.example.com',
                'port' => 5986,
                'use_ssl' => true,
                'meta' => ['hostname' => 'hv-host.example.com'],
            ],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());
        $html = view('admin.servers.partials._essential-panels', [
            'server' => $server->refresh(), 'vm' => $vm,
        ])->render();

        $this->assertStringContainsString('data-transport="strip"', $html);
        $this->assertSame(1, substr_count($html, 'data-transport="strip"'));
        $this->assertStringContainsString('hv-transport.example.com:5986', $html);
        $this->assertStringContainsString('SSL', $html);

        fwrite(STDERR, "\n[EVIDENCE hyperv-transport-strip] single strip with host:port + SSL badge\n");
    }

    public function test_panel_panels_render_no_transport_strip(): void
    {
        $server = $this->makeServer([
            'api_url' => 'https://panel.example.com:2087',
            'connection_meta' => ['hostname' => 'panel.example.com'],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());
        $html = view('admin.servers.partials._essential-panels', [
            'server' => $server->refresh(), 'vm' => $vm,
        ])->render();

        $this->assertStringNotContainsString('data-transport="strip"', $html);

        fwrite(STDERR, "\n[EVIDENCE panel-no-transport] cpanel panels render without transport strip\n");
    }

    public function test_hyperv_supplement_renders_switches_and_host_os(): void
    {
        $server = $this->makeServer([
            'server_type' => 'hyperv',
            'connection_meta' => [
                'meta' => [
                    'version' => '10.0.26100.1',
                    'hostOS' => 'Windows Server 2025',
                    'osBuild' => '26100',
                    'switchDetails' => [
                        ['name' => 'External vSwitch', 'type' => 'External'],
                        ['name' => 'Internal vSwitch', 'type' => 'Internal'],
                    ],
                ],
            ],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());
        $html = view('admin.servers.partials._essential-hyperv', [
            'server' => $server->refresh(), 'vm' => $vm,
        ])->render();

        $this->assertStringContainsString('Windows Server 2025', $html);
        $this->assertStringContainsString('External vSwitch', $html);
        $this->assertStringContainsString('Switches (2)', $html);

        fwrite(STDERR, "\n[EVIDENCE hyperv-supplement] Host OS + 2 switches rendered\n");
    }

    public function test_hyperv_supplement_empty_for_panel_server(): void
    {
        $server = $this->makeServer(['connection_meta' => ['hostname' => 'plain.example.com']]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());
        $html = view('admin.servers.partials._essential-hyperv', [
            'server' => $server->refresh(), 'vm' => $vm,
        ])->render();

        $this->assertStringNotContainsString('essentialHypervSupplement', $html);

        fwrite(STDERR, "\n[EVIDENCE supplement-empty] panel server renders no Hyper-V supplement\n");
    }

    public function test_stale_banner_never_checked_without_else_leak(): void
    {
        $server = $this->makeServer(['last_checked_at' => null]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());
        $vars = get_object_vars($vm);
        $vars['isStale'] = true;
        $vm = new ServerDetailViewModel(...$vars);

        $html = view('admin.servers.partials._essential-panels', [
            'server' => $server->refresh(), 'vm' => $vm, 'freshError' => null,
        ])->render();

        $this->assertStringContainsString('Live data may be stale — never checked.', $html);
        $this->assertStringNotContainsString('@else', $html);
        $this->assertStringNotContainsString('last checked', $html);

        fwrite(STDERR, "\n[EVIDENCE stale-never-checked] banner shows never-checked copy, no @else leak\n");
    }

    public function test_stale_banner_last_checked_without_else_leak(): void
    {
        $server = $this->makeServer(['last_checked_at' => now()->subMinutes(10)]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());
        $vars = get_object_vars($vm);
        $vars['isStale'] = true;
        $vm = new ServerDetailViewModel(...$vars);

        $html = view('admin.servers.partials._essential-panels', [
            'server' => $server->refresh(), 'vm' => $vm, 'freshError' => null,
        ])->render();

        $this->assertStringContainsString('Live data may be stale — last checked', $html);
        $this->assertStringNotContainsString('@else', $html);
        $this->assertStringNotContainsString('never checked', $html);

        fwrite(STDERR, "\n[EVIDENCE stale-last-checked] banner shows last-checked copy, no @else leak\n");
    }

    public function test_stale_banner_renders_fresh_error_reason(): void
    {
        $server = $this->makeServer(['last_checked_at' => now()->subMinutes(10)]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());
        $vars = get_object_vars($vm);
        $vars['isStale'] = true;
        $vm = new ServerDetailViewModel(...$vars);

        $html = view('admin.servers.partials._essential-panels', [
            'server' => $server->refresh(), 'vm' => $vm, 'freshError' => 'WinRM timed out after 8s',
        ])->render();

        $this->assertStringContainsString('Last refresh failed: WinRM timed out after 8s', $html);

        fwrite(STDERR, "\n[EVIDENCE stale-fresh-error] banner shows last refresh failure reason\n");
    }
}
