<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\RdpConsole\Models\RdpConsoleConfig;
use Modules\RdpConsole\Services\Gateway\GuacamoleLiteDriver;
use Tests\TestCase;

/**
 * Plan task 11 — RDP HTML5 console frontend + token endpoint.
 *
 * The token endpoint must hand the browser a decryptable {ws_url, token}
 * pair minted through the GatewayDriver (404 when the account has no
 * complete credentials), and the HTML console page must render the native
 * guacamole-common-js client — no iframe stub, no GUACAMOLE_URL gateway,
 * and never credential material in the page markup.
 */
final class RdpConsoleGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'feature-test-secret-0123456789abcdef';

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
    // Token endpoint: GET rdp.token returns decryptable JSON.
    // ------------------------------------------------------------------

    public function test_rdp_token_returns_decryptable_json(): void
    {
        $this->activateRdpConsoleModule();

        $product = $this->makeProduct();
        $account = $this->makeAccount($product);

        RdpConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => '203.0.113.80',
            'port' => 3391,
            'username' => 'administrator',
            'password_encrypted' => 'TOKEN-TARGET-PW',
            'domain' => 'CORP',
        ]);

        $response = $this->actingAsAdmin()
            ->get(route('admin.rdp-console.token', $account))
            ->assertOk()
            ->assertJsonStructure(['ws_url', 'token']);

        $payload = $response->json();

        $this->assertSame('ws://sidecar.test:9000/', $payload['ws_url']);
        // Plaintext credentials never travel outside the encrypted envelope.
        $this->assertIsString($payload['token']);
        $this->assertStringNotContainsString('TOKEN-TARGET-PW', $payload['token']);

        $settings = (new GuacamoleLiteDriver(secret: self::SECRET))->decryptForTest($payload['token']);

        $this->assertSame('rdp', $settings['connection']['type']);
        $connection = $settings['connection']['settings'];
        $this->assertSame('203.0.113.80', $connection['hostname']);
        $this->assertSame(3391, $connection['port']);
        $this->assertSame('administrator', $connection['username']);
        $this->assertSame('TOKEN-TARGET-PW', $connection['password']);
        $this->assertSame('CORP', $connection['domain']);
        $this->assertArrayHasKey('exp', $connection, 'Token must embed its TTL expiry.');
    }

    // ------------------------------------------------------------------
    // Failure: blank host / username / password → 404.
    // ------------------------------------------------------------------

    public function test_rdp_token_returns_404_when_credentials_blank(): void
    {
        $this->activateRdpConsoleModule();

        $product = $this->makeProduct();
        $partial = $this->makeAccount($product);

        RdpConsoleConfig::create([
            'hosting_account_id' => $partial->id,
            'host' => '203.0.113.81',
        ]);

        $unconfigured = $this->makeAccount($product);

        $this->actingAsAdmin()
            ->get(route('admin.rdp-console.token', $partial))
            ->assertNotFound();

        $this->actingAsAdmin()
            ->get(route('admin.rdp-console.token', $unconfigured))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // View: native guacamole-common-js client replaces the iframe stub.
    // ------------------------------------------------------------------

    public function test_rdp_html_view_replaces_iframe_stub_with_native_client(): void
    {
        $this->activateRdpConsoleModule();

        $product = $this->makeProduct();
        $account = $this->makeAccount($product);

        RdpConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => '203.0.113.82',
            'username' => 'administrator',
            'password_encrypted' => 'TOKEN-TARGET-PW',
        ]);

        $response = $this->actingAsAdmin()
            ->get(route('admin.rdp-console.html', $account))
            ->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringNotContainsStringIgnoringCase('iframe', $html, 'The old gateway iframe stub must be gone.');
        $this->assertStringContainsString('guacamole-common.min.js', $html, 'The vendored Guacamole client must be referenced.');
        $this->assertStringContainsString('guac-error-container', $html, 'The console error banner must exist in the markup.');
        $this->assertStringNotContainsString('TOKEN-TARGET-PW', $html, 'Credential material must never render.');
        $this->assertStringNotContainsString('GUACAMOLE_URL', $html);
    }

    // ------------------------------------------------------------------
    // Vendored client asset: exists, >30KB, served by its exact filename.
    // ------------------------------------------------------------------

    public function test_vendored_guacamole_client_exists_and_is_served(): void
    {
        $this->activateRdpConsoleModule();

        $path = base_path('modules/rdp-console/resources/assets/guacamole-common.min.js');

        $this->assertFileExists($path);
        $this->assertGreaterThan(30720, filesize($path), 'Vendored client must exceed 30KB.');

        $account = $this->makeAccount($this->makeProduct());

        $response = $this->actingAsAdmin()
            ->get(route('admin.rdp-console.clientAsset', $account))
            ->assertOk();

        // BinaryFileResponse streams from disk — inspect the attached file.
        $served = $response->getFile();
        $this->assertGreaterThan(30720, $served->getSize());
        $this->assertStringContainsString('javascript', strtolower((string) $response->headers->get('Content-Type')));
    }

    // ------------------------------------------------------------------
    // Guests are bounced to login (302), never shown console endpoints.
    // ------------------------------------------------------------------

    public function test_guest_is_redirected_from_console_endpoints(): void
    {
        $this->activateRdpConsoleModule();

        $account = $this->makeAccount($this->makeProduct());

        $this->get(route('admin.rdp-console.token', $account))->assertStatus(302);
        $this->get(route('admin.rdp-console.html', $account))->assertStatus(302);
        $this->get(route('admin.rdp-console.clientAsset', $account))->assertStatus(302);
    }

    // ==================================================================
    // Helpers (mirrors RdpConsoleModuleTest — real module, real routes).
    // ==================================================================

    /**
     * Reconcile the real modules folder, activate rdp-console and replay
     * the two side effects ModuleServiceProvider performs for active modules
     * during app boot: provider boot() (view namespace + bindings) and route
     * registration. Both are replayed because the app booted before the
     * module row existed in the test database.
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
        // Routes added after the router was compiled have a stale nameList:
        // refresh so route() resolves the final 'admin.rdp-console.*' names.
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();

        return $module;
    }

    private function makeProduct(): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::create(['name' => "Windows VPS {$sequence}"]);
    }

    private function makeAccount(Product $product, ?Customer $customer = null): HostingAccount
    {
        static $sequence = 0;
        $sequence++;

        $customer ??= Customer::create(['user_id' => User::factory()->create()->id]);

        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'username' => "winsrv{$sequence}",
            'status' => 'active',
        ]);
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
}
