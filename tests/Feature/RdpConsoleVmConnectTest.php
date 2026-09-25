<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Module;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\RdpConsole\Exceptions\GatewayNotConfiguredException;
use Modules\RdpConsole\Services\Gateway\GuacamoleLiteDriver;
use Tests\TestCase;

/**
 * Hyper-V VMConnect console — the module's second connection mode.
 *
 * Proves the security-critical properties:
 *   - the minted token carries security=vmconnect, port 2179, the VM GUID in
 *     the preconnection BLOB, the HOST's credentials and a connection-scoped
 *     ignore-cert;
 *   - guest credentials never reach a VMConnect token;
 *   - the endpoint is manage-gated (the read-only guest-RDP endpoints stay
 *     view-gated and are covered by RdpConsoleModuleTest);
 *   - a missing VM GUID or missing host credentials fails closed (no token);
 *   - the hosting panel offers the VM Console button only when the facts are
 *     present and the user holds hosting.manage.
 *
 * The live session itself is NOT exercised — guacd is not deployed in this
 * environment and TCP 2179 is unreachable. The evidence here is the decrypted
 * token payload and the rendered UI.
 */
final class RdpConsoleVmConnectTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'vmconnect-test-secret-0123456789ab';

    private const HOST = '10.1.3.133';

    /** The recorded VM GUID (PanelAccount.external_id). */
    private const GUID = 'be7b0864-7b3f-4429-9a20-8df961ef172f';

    /** The GUID the live host probe reports; preferred over the recorded one. */
    private const LIVE_GUID = '11111111-2222-3333-4444-555555555555';

    private const HOST_USER = 'HV-ADMIN';

    private const HOST_PW = 'HOST-ONLY-PW-42';

    private const GUEST_PW = 'GUEST-ONLY-PW-99';

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
    // Token minting: settings + credential isolation.
    // ------------------------------------------------------------------

    public function test_vmconnect_token_mints_hyperv_settings_with_host_credentials(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakeHostProbe(self::LIVE_GUID);

        $account = $this->makeHypervAccount($this->makeServer());

        $response = $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.vmConsoleToken', $account))
            ->assertOk()
            ->assertJsonStructure(['ws_url', 'token']);

        $payload = $response->json();

        $this->assertSame('ws://sidecar.test:9000/', $payload['ws_url']);
        $this->assertStringNotContainsString(self::HOST_PW, $payload['token']);
        $this->assertStringNotContainsString(self::GUEST_PW, $payload['token']);

        $connection = (new GuacamoleLiteDriver(secret: self::SECRET))
            ->decryptForTest($payload['token'])['connection']['settings'];

        $this->assertSame(self::HOST, $connection['hostname']);
        $this->assertSame(2179, $connection['port']);
        $this->assertSame('vmconnect', $connection['security']);
        $this->assertSame(
            self::LIVE_GUID,
            $connection['preconnection-blob'],
            'The live host probe GUID is preferred when the host answers.',
        );
        $this->assertTrue($connection['ignore-cert'], 'ignore-cert must be scoped to this VMConnect token.');
        $this->assertSame(self::HOST_USER, $connection['username'], 'VMConnect authenticates as the HYPER-V HOST.');
        $this->assertSame(self::HOST_PW, $connection['password'], 'The host administrator password, not the guest password.');
        $this->assertStringNotContainsString(
            self::GUEST_PW,
            (string) json_encode($connection),
            'Guest credentials must never reach a VMConnect token.',
        );
        $this->assertArrayNotHasKey('enable-drive', $connection);
        $this->assertArrayNotHasKey('resize-method', $connection);
        $this->assertArrayHasKey('exp', $connection);
    }

    public function test_vmconnect_token_falls_back_to_recorded_guid_when_probe_fails(): void
    {
        $this->activateRdpConsoleModule();
        // WinRM unreachable: the recorded external_id is still a usable GUID
        // because guacd reaches the host over a different path (TCP 2179).
        Http::fake(fn () => Http::response(['error' => 'WinRM boom'], 500));

        $account = $this->makeHypervAccount($this->makeServer());

        $payload = $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.vmConsoleToken', $account))
            ->assertOk()
            ->json();

        $connection = (new GuacamoleLiteDriver(secret: self::SECRET))
            ->decryptForTest($payload['token'])['connection']['settings'];

        $this->assertSame(self::GUID, $connection['preconnection-blob']);
        $this->assertSame('vmconnect', $connection['security']);
    }

    // ------------------------------------------------------------------
    // Permission gate: manage only, never view/edit.
    // ------------------------------------------------------------------

    public function test_vmconnect_endpoints_require_hosting_manage(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakeHostProbe();

        $account = $this->makeHypervAccount($this->makeServer());

        // hosting.view alone gates the read-only guest-RDP endpoints; it must
        // not unlock the interactive VM console.
        $this->actingAsWithPermissions(['hosting.view'])
            ->get(route('admin.rdp-console.vmConsoleToken', $account))
            ->assertForbidden();

        $this->actingAsWithPermissions(['hosting.view', 'hosting.edit'])
            ->get(route('admin.rdp-console.vmConsoleToken', $account))
            ->assertForbidden();

        $this->actingAsWithPermissions(['hosting.view', 'hosting.edit'])
            ->get(route('admin.rdp-console.vmConsole', $account))
            ->assertForbidden();

        // The manage holder gets both.
        $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.vmConsoleToken', $account))
            ->assertOk();

        $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.vmConsole', $account))
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // Fail closed: no VM GUID / no host credentials → no token.
    // ------------------------------------------------------------------

    public function test_vmconnect_token_fails_closed_without_a_vm_guid(): void
    {
        $this->activateRdpConsoleModule();
        // The host answers but reports no such VM, and the panel row records
        // no GUID at all.
        $this->fakeHostProbe(['exists' => false]);

        $account = $this->makeHypervAccount($this->makeServer(), [
            'external_id' => null,
            'meta' => ['vmName' => 'hv-vm-01'],
        ]);

        $response = $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.vmConsoleToken', $account))
            ->assertNotFound();

        $this->assertArrayNotHasKey('token', $response->json());
    }

    public function test_vmconnect_token_fails_closed_without_host_credentials(): void
    {
        $this->activateRdpConsoleModule();
        Http::fake();

        $server = $this->makeServer(['api_username' => '', 'api_password_encrypted' => null]);
        $account = $this->makeHypervAccount($server);

        $response = $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.vmConsoleToken', $account))
            ->assertNotFound();

        $this->assertArrayNotHasKey('token', $response->json());
        // Credential resolution must fail before any host probe is attempted.
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Gateway not configured: graceful 503 + honest page state.
    // ------------------------------------------------------------------

    public function test_vmconnect_token_returns_503_and_leaks_nothing_when_gateway_is_unconfigured(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakeHostProbe();
        // The .env has no GUACAMOLE_SECRET: minting must fail closed, but the
        // endpoint must answer gracefully instead of escaping as a 500.
        config()->set('rdp-console.secret', null);

        $account = $this->makeHypervAccount($this->makeServer());

        $response = $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.vmConsoleToken', $account))
            ->assertStatus(503);

        $body = (string) $response->getContent();

        // Actionable: names the setting to fix.
        $this->assertStringContainsString('GUACAMOLE_SECRET', $body);
        $this->assertSame(
            GatewayNotConfiguredException::OPERATOR_MESSAGE,
            $response->json('error'),
            'The endpoint must return the fixed operator message, never the exception message.',
        );
        $this->assertArrayNotHasKey('token', $response->json());

        // No exception text, class, file path, trace or config value.
        foreach ([
            'GUACAMOLE_SECRET is not configured',
            'GatewayNotConfiguredException',
            'RuntimeException',
            'derivedKey',
            'GuacamoleLiteDriver',
            'Stack trace',
            'vendor',
            '.php',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "The 503 body must not leak '{$needle}'.");
        }
    }

    public function test_vmconsole_page_renders_not_configured_state_without_a_working_connect(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakeHostProbe();
        config()->set('rdp-console.secret', null);

        $account = $this->makeHypervAccount($this->makeServer());

        $html = (string) $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.vmConsole', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('console gateway is not configured', $html);
        $this->assertStringContainsString('GUACAMOLE_SECRET', $html);
        $this->assertMatchesRegularExpression(
            '/id="guac-connect"[^>]*disabled/',
            $html,
            'Connect must not be offered as a working action when the gateway cannot mint.',
        );
    }

    public function test_pages_offer_a_working_connect_when_the_gateway_is_configured(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakeHostProbe();

        $account = $this->makeHypervAccount($this->makeServer());

        $this->actingAsWithPermissions(['hosting.view', 'hosting.manage']);

        $html = (string) $this->get(route('admin.rdp-console.vmConsole', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('console gateway is not configured', $html);
        $this->assertDoesNotMatchRegularExpression('/id="guac-connect"[^>]*disabled/', $html);

        // The happy path still mints when the secret is set.
        $this->get(route('admin.rdp-console.vmConsoleToken', $account))
            ->assertOk()
            ->assertJsonStructure(['ws_url', 'token']);
    }

    // ------------------------------------------------------------------
    // Console page: shared canvas, token endpoint, no credential material.
    // ------------------------------------------------------------------

    public function test_vmconsole_page_renders_the_shared_canvas(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakeHostProbe();

        $account = $this->makeHypervAccount($this->makeServer());

        $response = $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.vmConsole', $account))
            ->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringContainsString('guacamole-common.min.js', $html);
        $this->assertStringContainsString('guac-error-container', $html);
        // The token URL travels inside @json(), which escapes forward slashes.
        $this->assertStringContainsString(
            (string) json_encode(route('admin.rdp-console.vmConsoleToken', $account)),
            $html,
            'The canvas must fetch the VMConnect token endpoint.',
        );
        $this->assertStringContainsString(self::LIVE_GUID, $html);
        $this->assertStringNotContainsString(self::HOST_PW, $html, 'Credential material must never render.');
        $this->assertStringNotContainsString(self::GUEST_PW, $html, 'Guest credentials must never render.');
    }

    public function test_vmconsole_page_explains_when_unavailable(): void
    {
        $this->activateRdpConsoleModule();
        Http::fake();

        $server = $this->makeServer(['api_username' => '', 'api_password_encrypted' => null]);
        $account = $this->makeHypervAccount($server);

        $response = $this->actingAsWithPermissions(['hosting.view', 'hosting.manage'])
            ->get(route('admin.rdp-console.vmConsole', $account))
            ->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringContainsString('host administrator credentials are not configured', $html);
        $this->assertStringContainsString('unavailable', $html);
    }

    // ------------------------------------------------------------------
    // Hosting panel: the VM Console button's three states.
    // ------------------------------------------------------------------

    public function test_hosting_show_offers_vm_console_button_when_available_and_permitted(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakeHostProbe();

        $account = $this->makeHypervAccount($this->makeServer());

        $html = (string) $this->actingAsWithPermissions(['hosting.view', 'hosting.edit', 'hosting.manage'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('VM Console', $html);
        $this->assertStringContainsString(
            route('admin.rdp-console.vmConsole', $account),
            $html,
            'An available console must be a working link.',
        );
    }

    public function test_hosting_show_omits_vm_console_button_without_hosting_manage(): void
    {
        $this->activateRdpConsoleModule();
        $this->fakeHostProbe();

        $account = $this->makeHypervAccount($this->makeServer());

        $html = (string) $this->actingAsWithPermissions(['hosting.view', 'hosting.edit'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('VM Console', $html);
        $this->assertStringNotContainsString('/rdp-console/vm-console', $html);
    }

    public function test_hosting_show_disables_vm_console_button_without_host_credentials(): void
    {
        $this->activateRdpConsoleModule();
        Http::fake();

        $server = $this->makeServer(['api_username' => '', 'api_password_encrypted' => null]);
        $account = $this->makeHypervAccount($server);

        $html = (string) $this->actingAsWithPermissions(['hosting.view', 'hosting.edit', 'hosting.manage'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        // Offered but disabled — and the reason is visible text, not just a
        // title, because a disabled button is not focusable.
        $this->assertStringContainsString('VM Console', $html);
        $this->assertStringNotContainsString('/rdp-console/vm-console', $html, 'A disabled console must not link.');
        $this->assertStringContainsString('No Hyper-V host administrator credentials are configured for this service.', $html);
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

    private function makeServer(array $overrides = []): Server
    {
        static $sequence = 0;
        $sequence++;

        return Server::create(array_merge([
            'name' => "hv-host-{$sequence}",
            'ip_address' => '10.9.9.9',
            'server_type' => 'hyperv',
            'api_url' => 'http://'.self::HOST.':5985',
            'api_username' => self::HOST_USER,
            'api_password_encrypted' => self::HOST_PW,
            'max_accounts' => 0,
            'status' => 'active',
        ], $overrides));
    }

    /**
     * A hosting account with the full Hyper-V chain the resolver walks:
     * ProductModule(hyperv) → HostingAccount → ServiceInstance (HOST-{id})
     * → PanelAccount(panel=hyperv) with guest credentials recorded (which the
     * VMConnect path must never use).
     */
    private function makeHypervAccount(Server $server, array $panelOverrides = []): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        $product = Product::create(['name' => "Windows VPS {$sequence}", 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'config' => [],
        ]);

        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'host_name' => "hv-vm-{$sequence}",
            'status' => 'active',
        ]);

        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'service_tag' => 'HOST-'.$account->id,
            'username' => $account->host_name,
            'provisioning_method' => 'hyperv',
            'status' => 'pending',
        ]);

        PanelAccount::create(array_merge([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'hyperv',
            'username' => $account->host_name,
            'external_id' => self::GUID,
            'guest_username' => 'Administrator',
            'guest_password_encrypted' => self::GUEST_PW,
            'meta' => ['vmName' => $account->host_name],
            'status' => PanelAccount::STATUS_ACTIVE,
        ], $panelOverrides));

        return $account->fresh();
    }

    /**
     * Fake the WinRM Get-VM probe. Pass a GUID string for "VM exists", or a
     * raw payload array (e.g. ['exists' => false]).
     *
     * @param  array<string, mixed>|string  $result
     */
    private function fakeHostProbe(array|string $result = self::LIVE_GUID): void
    {
        Http::fake(function ($request) use ($result) {
            if (str_contains((string) $request->body(), 'Get-VM')) {
                return Http::response(is_array($result) ? $result : [
                    'exists' => true,
                    'name' => 'hv-vm-01',
                    'state' => 'Off',
                    'vmId' => $result,
                ]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });
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
            'name' => "vmconnect-test-role-{$sequence}",
            'label' => 'VMConnect test role',
        ]);

        $role->permissions()->sync(
            Permission::whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->syncWithoutDetaching($role->id);

        return $this->actingAs($user->fresh());
    }
}
