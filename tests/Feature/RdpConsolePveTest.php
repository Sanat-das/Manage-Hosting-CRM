<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Module;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\RdpConsole\Exceptions\GatewayNotConfiguredException;
use Modules\RdpConsole\Exceptions\PveVncUnavailableException;
use Modules\RdpConsole\Services\Gateway\GuacamoleLiteDriver;
use Modules\RdpConsole\Services\PveVnc\PveVncTargetResolver;
use Tests\TestCase;

/**
 * Proxmox VE VNC console — the module's third connection mode.
 *
 * Proves the security-critical properties:
 *   - the minted token carries type `vnc` with inert relay placeholders plus
 *     the exact `pve` relay block (API host, node, VMID, one-shot ticket);
 *   - no part of the target comes from the request — everything resolves
 *     server-side from the account's PanelAccount and Server rows;
 *   - the endpoint is manage-gated (view-only → 403);
 *   - every missing fact fails closed (no token, 404);
 *   - the page renders the shared canvas wired to the PVE token endpoint and
 *     never leaks the token secret.
 *
 * The live session itself is NOT exercised — guacd and the sidecar relay are
 * not deployed in this environment. The evidence here is the decrypted token
 * payload and the rendered UI.
 */
final class RdpConsolePveTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'pve-console-test-secret-0123456789';

    private const API_HOST = 'pve1.example.internal';

    private const NODE = 'pve1';

    private const VMID = 901;

    private const VNC_PORT = 5900;

    private const VNC_TICKET = 'PVEVNC:abcdef1234';

    private const TOKEN_ID = 'root@pam!automation';

    private const TOKEN_SECRET = 'PVE-TOKEN-SECRET-42';

    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(ModuleManager::class);

        config()->set('rdp-console.secret', self::SECRET);
        config()->set('rdp-console.ws_url', 'ws://sidecar.test:9000/');
        config()->set('rdp-console.recording_path', null);
    }

    // ------------------------------------------------------------------
    // Token minting: contract payload.
    // ------------------------------------------------------------------

    public function test_pve_token_mints_the_sidecar_relay_contract(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakePve();

        $account = $this->makeProxmoxAccount($this->makeServer());

        $response = $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.pveConsoleToken', $account))
            ->assertOk()
            ->assertJsonStructure(['ws_url', 'token']);

        $payload = $response->json();

        $this->assertSame('ws://sidecar.test:9000/', $payload['ws_url']);
        $this->assertStringNotContainsString(self::TOKEN_SECRET, $payload['token']);
        $this->assertStringNotContainsString(self::VNC_TICKET, $payload['token']);

        $decrypted = (new GuacamoleLiteDriver(secret: self::SECRET))->decryptForTest($payload['token']);

        $this->assertSame('vnc', $decrypted['connection']['type']);
        $this->assertSame(
            ['hostname', 'port', 'password', 'exp'],
            array_keys($decrypted['connection']['settings']),
        );
        $this->assertSame('127.0.0.1', $decrypted['connection']['settings']['hostname']);
        $this->assertSame(0, $decrypted['connection']['settings']['port']);
        $this->assertSame('', $decrypted['connection']['settings']['password']);
        $this->assertIsInt($decrypted['connection']['settings']['exp']);

        $this->assertSame([
            'apiHost' => self::API_HOST,
            'apiPort' => 8006,
            'verifyTls' => false,
            'tokenId' => self::TOKEN_ID,
            'tokenSecret' => self::TOKEN_SECRET,
            'node' => self::NODE,
            'vmid' => self::VMID,
            'vncPort' => self::VNC_PORT,
            'vncTicket' => self::VNC_TICKET,
        ], $decrypted['pve']);

        // The canvas mints on connect, so the token endpoint must mint exactly
        // one vncproxy ticket per call — the page itself mints none.
        $vncProxyCalls = Http::recorded(static fn ($request): bool => str_contains($request->url(), 'vncproxy'));
        $this->assertCount(1, $vncProxyCalls, 'The token endpoint must mint exactly one vncproxy ticket.');
    }

    // ------------------------------------------------------------------
    // Resolver: happy path + every failure mode.
    // ------------------------------------------------------------------

    public function test_resolver_builds_the_pve_block_from_panel_account_and_server(): void
    {
        $this->fakePve();

        $account = $this->makeProxmoxAccount($this->makeServer());

        $pve = app(PveVncTargetResolver::class)->resolve($account);

        $this->assertSame(self::API_HOST, $pve['apiHost']);
        $this->assertSame(8006, $pve['apiPort']);
        $this->assertFalse($pve['verifyTls']);
        $this->assertSame(self::TOKEN_ID, $pve['tokenId']);
        $this->assertSame(self::TOKEN_SECRET, $pve['tokenSecret']);
        $this->assertSame(self::NODE, $pve['node']);
        $this->assertSame(self::VMID, $pve['vmid']);
        $this->assertSame(self::VNC_PORT, $pve['vncPort']);
        $this->assertSame(self::VNC_TICKET, $pve['vncTicket']);
    }

    public function test_resolver_throws_without_a_proxmox_panel_account(): void
    {
        Http::fake();

        $account = $this->makeBareAccount();

        $this->expectException(PveVncUnavailableException::class);

        app(PveVncTargetResolver::class)->resolve($account);
    }

    public function test_token_endpoint_returns_404_without_a_panel_account(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakePve();

        $response = $this->tokenResponse($this->makeBareAccount());

        $response->assertNotFound();
        $this->assertArrayNotHasKey('token', $response->json());
    }

    public function test_token_endpoint_returns_404_without_a_recorded_vmid(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakePve();

        $account = $this->makeProxmoxAccount($this->makeServer(), ['external_id' => null, 'meta' => ['node' => self::NODE]]);

        $response = $this->tokenResponse($account);

        $response->assertNotFound();
        $this->assertArrayNotHasKey('token', $response->json());
    }

    public function test_token_endpoint_returns_404_with_an_unconfigured_server(): void
    {
        $this->activateRdpConsoleModule();
        Http::fake();

        $account = $this->makeProxmoxAccount($this->makeServer(['api_username' => '']));

        $response = $this->tokenResponse($account);

        $response->assertNotFound();
        $this->assertArrayNotHasKey('token', $response->json());

        // Credential resolution must fail before any PVE call is attempted.
        Http::assertNothingSent();
    }

    public function test_token_endpoint_returns_404_when_vncproxy_fails(): void
    {
        $this->activateRdpConsoleModule();
        Http::fake([
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 901]]),
            '*/api2/json/nodes/pve1/qemu/901/vncproxy' => Http::response(['message' => 'no such vm'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $account = $this->makeProxmoxAccount($this->makeServer());

        $response = $this->tokenResponse($account);

        $response->assertNotFound();
        $this->assertArrayNotHasKey('token', $response->json());
    }

    public function test_token_endpoint_returns_404_without_a_resolvable_node(): void
    {
        $this->activateRdpConsoleModule();
        Http::fake([
            '*/api2/json/nodes' => Http::response(['data' => []]),
            '*' => Http::response(['data' => []]),
        ]);

        $account = $this->makeProxmoxAccount($this->makeServer(), ['meta' => []]);

        $response = $this->tokenResponse($account);

        $response->assertNotFound();
        $this->assertArrayNotHasKey('token', $response->json());
    }

    // ------------------------------------------------------------------
    // Permission gate: manage only.
    // ------------------------------------------------------------------

    public function test_pve_endpoints_require_hosting_manage(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakePve();

        $account = $this->makeProxmoxAccount($this->makeServer());

        $this->actingAsWithPermissions(['hosting.view'])
            ->get(route('admin.rdp-console.pveConsoleToken', $account))
            ->assertForbidden();

        $this->actingAsWithPermissions(['hosting.view', 'hosting.edit'])
            ->get(route('admin.rdp-console.pveConsole', $account))
            ->assertForbidden();

        $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.pveConsoleToken', $account))
            ->assertOk();

        $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.pveConsole', $account))
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // Gateway not configured: graceful 503 + honest page state.
    // ------------------------------------------------------------------

    public function test_pve_token_returns_503_and_leaks_nothing_when_gateway_is_unconfigured(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakePve();
        config()->set('rdp-console.secret', null);

        $account = $this->makeProxmoxAccount($this->makeServer());

        $response = $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.pveConsoleToken', $account))
            ->assertStatus(503);

        $body = (string) $response->getContent();

        $this->assertSame(
            GatewayNotConfiguredException::OPERATOR_MESSAGE,
            $response->json('error'),
            'The endpoint must return the fixed operator message, never the exception message.',
        );
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertStringNotContainsString(self::TOKEN_SECRET, $body);
        $this->assertStringNotContainsString(self::VNC_TICKET, $body);
    }

    // ------------------------------------------------------------------
    // Console page: shared canvas, token endpoint, no secret material.
    // ------------------------------------------------------------------

    public function test_pve_page_renders_the_shared_canvas_without_secret_material(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakePve();

        $account = $this->makeProxmoxAccount($this->makeServer());

        $html = (string) $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.pveConsole', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('guacamole-common.min.js', $html);
        $this->assertStringContainsString('guac-error-container', $html);
        $this->assertStringContainsString(
            (string) json_encode(route('admin.rdp-console.pveConsoleToken', $account)),
            $html,
            'The canvas must fetch the PVE token endpoint.',
        );
        $this->assertStringContainsString(self::NODE, $html);
        $this->assertStringContainsString((string) self::VMID, $html);
        $this->assertStringNotContainsString(
            '<th class="text-muted">VNC port</th>',
            $html,
            'The page uses facts-only mode — the port is assigned when the console connects.',
        );
        $this->assertStringContainsString('sidecar', $html);
        $this->assertStringNotContainsString(self::TOKEN_SECRET, $html, 'Credential material must never render.');
        $this->assertStringNotContainsString(self::VNC_TICKET, $html, 'The one-shot ticket must never render.');

        // Facts-only mode must not burn a PVE write just to render the page.
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'vncproxy'));
    }

    public function test_pve_page_resolver_mode_skips_the_vncproxy_write(): void
    {
        $this->fakePve();

        $account = $this->makeProxmoxAccount($this->makeServer());

        $pve = app(PveVncTargetResolver::class)->resolve($account, false);

        $this->assertSame(self::NODE, $pve['node']);
        $this->assertSame(self::VMID, $pve['vmid']);
        $this->assertNull($pve['vncPort']);
        $this->assertNull($pve['vncTicket']);

        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'vncproxy'));
    }

    public function test_pve_page_explains_when_unavailable(): void
    {
        $this->activateRdpConsoleModule();
        Http::fake();

        $server = $this->makeServer(['api_username' => '']);
        $account = $this->makeProxmoxAccount($server);

        $html = (string) $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.pveConsole', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('not configured', $html);
        $this->assertStringContainsString('unavailable', $html);
        $this->assertMatchesRegularExpression(
            '/id="guac-connect"[^>]*disabled/',
            $html,
            'Connect must not be offered as a working action when the console cannot mint.',
        );
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Reconcile the real modules folder, activate rdp-console and replay the
     * side effects ModuleServiceProvider performs for active modules during
     * app boot: provider boot() (view namespace + bindings) and route
     * registration.
     */
    private function activateRdpConsoleModule(): Module
    {
        $this->manager->reconcile();

        $module = $this->manager->find('rdp-console');
        $this->assertNotNull($module, 'rdp-console module must be discovered from base_path(\'modules\').');

        $this->manager->activate($module);
        $this->assertSame(Module::STATUS_ACTIVE, $module->fresh()->status);

        $instance = $this->manager->resolve($module);
        $this->assertNotNull($instance, 'rdp-console provider must resolve.');

        $instance->boot($this->manager->contextFor($module));
        $this->manager->registerModuleRoutes();
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();

        return $module;
    }

    /**
     * Fake the PVE surface the resolver touches, specific routes first:
     * the recorded VM exists on its node and vncproxy answers a ticket.
     */
    private function fakePve(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            '*/api2/json/nodes/pve1/qemu/901/vncproxy' => Http::response(['data' => [
                'port' => self::VNC_PORT,
                'ticket' => self::VNC_TICKET,
            ]]),
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 901]]),
            '*/api2/json/nodes' => Http::response(['data' => [
                ['node' => 'pve1', 'status' => 'online'],
            ]]),
        ]);
    }

    /**
     * The PVE token endpoint as a manage-gated user.
     */
    private function tokenResponse(HostingAccount $account): TestResponse
    {
        return $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.pveConsoleToken', $account));
    }

    private function makeServer(array $overrides = []): Server
    {
        static $sequence = 0;
        $sequence++;

        return Server::create(array_merge([
            'name' => "pve-host-{$sequence}",
            'ip_address' => '10.0.0.20',
            'server_type' => 'proxmox',
            'api_url' => 'https://'.self::API_HOST.':8006',
            'api_username' => self::TOKEN_ID,
            'api_password_encrypted' => self::TOKEN_SECRET,
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => [
                'port' => 8006,
                'auth_type' => 'token',
                'verify_tls' => false,
            ],
        ], $overrides));
    }

    /**
     * A hosting account with the full Proxmox chain the resolver walks:
     * HostingAccount → ServiceInstance (HOST-{id}) → PanelAccount
     * (panel=proxmox, external_id=VMID, meta.node).
     */
    private function makeProxmoxAccount(Server $server, array $panelOverrides = []): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        $product = Product::create(['name' => "Proxmox VPS {$sequence}", 'price' => 50]);

        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'pve-vm.test',
            'host_name' => "pve-vm-{$sequence}",
            'status' => 'active',
        ]);

        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'server_id' => $server->id,
            'domain' => 'pve-vm.test',
            'service_tag' => 'HOST-'.$account->id,
            'username' => $account->host_name,
            'provisioning_method' => 'proxmox',
            'status' => 'pending',
        ]);

        PanelAccount::create(array_merge([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => $account->host_name,
            'external_id' => (string) self::VMID,
            'meta' => ['node' => self::NODE, 'vmid' => self::VMID],
            'status' => PanelAccount::STATUS_ACTIVE,
        ], $panelOverrides));

        return $account->fresh();
    }

    /**
     * A hosting account with no service/panel chain at all — the resolver
     * must fail closed on it.
     */
    private function makeBareAccount(): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        $product = Product::create(['name' => "Bare VPS {$sequence}", 'price' => 10]);

        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => null,
            'domain' => 'bare.test',
            'host_name' => "bare-vm-{$sequence}",
            'status' => 'active',
        ]);
    }

    /**
     * A panel user whose bespoke role carries EXACTLY the given permissions.
     *
     * Deliberately NOT an 'admin'-named role: hasPermission() short-circuits on
     * isAdmin(), so an admin-role user would pass every gate regardless of the
     * synced permissions and a negative assertion would prove nothing. The
     * baseline column role is 'marketing' — the one panel role with no
     * hosting.* permissions at all.
     */
    private function actingAsWithPermissions(array $permissionNames): self
    {
        static $sequence = 0;
        $sequence++;

        $user = User::factory()->create(['role' => 'marketing']);

        $role = Role::create([
            'name' => "pve-test-role-{$sequence}",
            'label' => 'PVE test role',
        ]);

        $role->permissions()->sync(
            Permission::whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->syncWithoutDetaching($role->id);

        return $this->actingAs($user->fresh());
    }
}
