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
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\RdpConsole\Models\RdpConsoleConfig;
use Modules\RdpConsole\RdpConsole;
use Tests\TestCase;

/**
 * Windows Server (RDP) module end-to-end: lifecycle, RDP-only config
 * schema and the RDP CRUD / download / password-reveal routes — no live
 * VPS required.
 *
 * Unlike ModuleManagerTest / ModuleLifecycleTest this test intentionally
 * does NOT repoint modules.path at the fixtures directory: it exercises the
 * real rdp-console module discovered from base_path('modules').
 *
 * Token minting THROUGH the controller route (GET rdp.token) with an
 * AES decryptable round-trip is covered by
 * RdpConsoleGatewayTest::test_rdp_token_returns_decryptable_json —
 * intentionally not duplicated here.
 */
class RdpConsoleModuleTest extends TestCase
{
    use RefreshDatabase;

    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(ModuleManager::class);
    }

    // ------------------------------------------------------------------
    // a) Lifecycle: reconcile creates the row, activation runs migrations.
    // ------------------------------------------------------------------

    public function test_module_reconciles_and_activates(): void
    {
        $module = $this->activateRdpConsoleModule();

        $this->assertSame(Module::STATUS_ACTIVE, $module->fresh()->status);
        $this->assertSame('RDP Console', $module->fresh()->name);
        $this->assertTrue(Schema::hasTable('rdp_console_configs'), 'RDP migration must run on activate.');
        $this->assertSame(1, DB::table('module_migrations')->where('module_id', $module->id)->count());

        $instance = $this->manager->resolve($module);

        $this->assertInstanceOf(RdpConsole::class, $instance);
    }

    // ------------------------------------------------------------------
    // b) Config schema: empty — SNMP product-config moved to snmp-monitor.
    // ------------------------------------------------------------------

    public function test_config_schema_is_empty_for_rdp_only_module(): void
    {
        $module = $this->activateRdpConsoleModule();

        $instance = $this->manager->resolve($module);
        $this->assertNotNull($instance);

        $schema = $instance->configSchema();

        $this->assertSame(['fields' => []], $schema);
    }

    // ------------------------------------------------------------------
    // b2) Route scope guard: the module registers ONLY the RDP route set.
    //     A reintroduced admin.rdp-console.refresh (legacy SNMP poll)
    //     registration must fail this suite loudly.
    // ------------------------------------------------------------------

    public function test_module_registers_only_the_rdp_route_set(): void
    {
        $this->activateRdpConsoleModule();

        $names = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn (Route $route): ?string => $route->getName())
            ->filter(fn (?string $name): bool => $name !== null && str_starts_with($name, 'admin.rdp-console.'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'admin.rdp-console.clientAsset',
            'admin.rdp-console.download',
            'admin.rdp-console.edit',
            'admin.rdp-console.html',
            'admin.rdp-console.password',
            'admin.rdp-console.token',
            'admin.rdp-console.update',
        ], $names, 'rdp-console must register ONLY the RDP-scoped routes — no refresh/SNMP leftovers.');
    }

    // ------------------------------------------------------------------
    // c) RDP update: persists per-account settings; blank input keeps an
    //    existing password instead of nulling it.
    // ------------------------------------------------------------------

    public function test_rdp_update_persists_settings_and_blank_password_keeps_existing(): void
    {
        $module = $this->activateRdpConsoleModule();

        $product = $this->makeProduct();
        $account = $this->makeAccount($product);

        $this->actingAsAdmin()
            ->from(route('admin.hosting.show', $account))
            ->put(route('admin.rdp-console.update', $account), [
                'host' => '203.0.113.50',
                'port' => '3390',
                'username' => 'administrator',
                'password' => 'FIRST-SECRET-PW',
                'domain' => 'CORP',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'RDP settings saved.');

        $config = RdpConsoleConfig::query()->where('hosting_account_id', $account->id)->firstOrFail();

        $this->assertSame('203.0.113.50', $config->host);
        $this->assertSame(3390, $config->port);
        $this->assertSame('administrator', $config->username);
        $this->assertSame('CORP', $config->domain);
        $this->assertSame('FIRST-SECRET-PW', $config->password_encrypted);

        // Second save without a password must NOT wipe the stored one.
        $this->actingAsAdmin()
            ->put(route('admin.rdp-console.update', $account), [
                'host' => '203.0.113.51',
                'password' => '',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'RDP settings saved.');

        $config = RdpConsoleConfig::query()->where('hosting_account_id', $account->id)->firstOrFail();

        $this->assertSame('203.0.113.51', $config->host);
        $this->assertSame(3389, $config->port, 'Blank port falls back to the 3389 default.');
        $this->assertSame('FIRST-SECRET-PW', $config->password_encrypted, 'Empty password input keeps existing.');
    }

    // ------------------------------------------------------------------
    // d) RDP download: streams a .rdp file containing the full address.
    // ------------------------------------------------------------------

    public function test_rdp_download_streams_rdp_file_with_full_address(): void
    {
        $module = $this->activateRdpConsoleModule();

        $product = $this->makeProduct();
        $account = $this->makeAccount($product);

        RdpConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => '203.0.113.60',
            'username' => 'administrator',
        ]);

        $response = $this->actingAsAdmin()
            ->get(route('admin.rdp-console.download', $account))
            ->assertOk();

        $content = $response->streamedContent();

        $this->assertStringContainsString('full address:s:203.0.113.60:3389', $content);
        $this->assertStringContainsString('username:s:administrator', $content);
        $this->assertSame('application/x-rdp', $response->headers->get('Content-Type'));
    }

    // ------------------------------------------------------------------
    // e) Password reveal: decrypted value returned only via the dedicated
    //    endpoint; never embedded in the download or edit responses above,
    //    and gated on hosting.view (view-gated, NOT manage-gated).
    // ------------------------------------------------------------------

    public function test_rdp_password_endpoint_reveals_decrypted_value(): void
    {
        $module = $this->activateRdpConsoleModule();

        $product = $this->makeProduct();
        $account = $this->makeAccount($product);

        RdpConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => '203.0.113.70',
            'password_encrypted' => 'SUPERSECRET-RDP-PW',
        ]);

        $this->actingAsAdmin()
            ->get(route('admin.rdp-console.password', $account))
            ->assertOk()
            ->assertJson(['password' => 'SUPERSECRET-RDP-PW']);
    }

    public function test_rdp_password_endpoint_returns_null_when_not_set(): void
    {
        $module = $this->activateRdpConsoleModule();

        $product = $this->makeProduct();
        $account = $this->makeAccount($product);

        $this->actingAsAdmin()
            ->get(route('admin.rdp-console.password', $account))
            ->assertOk()
            ->assertJson(['password' => null]);
    }

    public function test_rdp_password_endpoint_forbids_admins_without_hosting_view(): void
    {
        $module = $this->activateRdpConsoleModule();

        $product = $this->makeProduct();
        $account = $this->makeAccount($product);

        RdpConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => '203.0.113.72',
            'password_encrypted' => 'SUPERSECRET-RDP-PW',
        ]);

        // A panel user without hosting.view must be denied.
        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get(route('admin.rdp-console.password', $account))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->get(route('admin.rdp-console.password', $account))
            ->assertForbidden();
    }

    public function test_rdp_password_endpoint_allows_view_only_admin(): void
    {
        $module = $this->activateRdpConsoleModule();

        $product = $this->makeProduct();
        $account = $this->makeAccount($product);

        RdpConsoleConfig::create([
            'hosting_account_id' => $account->id,
            'host' => '203.0.113.73',
            'password_encrypted' => 'VIEWONLY-RDP-PW',
        ]);

        // hosting.view alone is sufficient — the reveal is view-gated,
        // not manage-gated.
        $this->actingAsWithPermissions(['hosting.view'])
            ->get(route('admin.rdp-console.password', $account))
            ->assertOk()
            ->assertJson(['password' => 'VIEWONLY-RDP-PW']);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Reconcile the real modules folder, activate rdp-console and replay
     * the two side effects ModuleServiceProvider performs for active modules
     * during app boot: provider boot() (view namespace) and route
     * registration. Both must be replayed because the app booted before the
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
        // RouteCollection::add() captures the name at add() time (prefix only),
        // and ->name(...) mutates the Route afterwards without updating the
        // lookup. Refresh rebuilds the tables so route() / getByName() resolve
        // the final 'admin.rdp-console.*' names.
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
        return $this->actingAsWithPermissions(['hosting.view', 'hosting.manage']);
    }

    /**
     * Admin whose role carries EXACTLY the given permissions — sync() (not
     * syncWithoutDetaching) so a less-privileged user created after a
     * privileged one in the same test cannot inherit earlier grants.
     */
    private function actingAsWithPermissions(array $permissionNames): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        $permissionIds = [];

        foreach ($permissionNames as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $permissionIds[] = $perm->id;
        }

        $adminRole->permissions()->sync($permissionIds);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
