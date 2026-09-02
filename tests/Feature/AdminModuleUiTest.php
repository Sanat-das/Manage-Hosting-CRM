<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HostingAccount;
use App\Models\IpAddress;
use App\Models\IpSubnet;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithModuleFixtures;
use Tests\TestCase;

/**
 * Admin web UI for modules: every module route is gated behind its
 * permission (modules.view / modules.manage / products.edit), and per-product
 * module config persists schema-encrypted values.
 *
 * Authentication mirrors the repo's existing admin feature tests: a User with
 * the 'admin' role plus exactly the permissions the scenario needs.
 */
class AdminModuleUiTest extends TestCase
{
    use InteractsWithModuleFixtures;
    use RefreshDatabase;

    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpModuleFixtures();
        $this->manager = app(ModuleManager::class);
    }

    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        // Permissions live on the ROLE (RBAC), so a "no permission" user
        // created after a privileged one in the same test must not inherit
        // the earlier grants — sync() makes the role's set exactly ours.
        $permissionIds = [];

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => ucwords(str_replace('.', ' ', $name))]
            );
            $permissionIds[] = $permission->id;
        }

        $adminRole->permissions()->sync($permissionIds);

        $user->assignRole('admin');

        return $user;
    }

    private function actingAsAdminWith(array $permissionNames): self
    {
        return $this->actingAs($this->userWithPermissions($permissionNames));
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'name' => 'Shared Hosting Basic',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'active',
        ]);
    }

    private function activatedOkModule(): Module
    {
        $this->manager->reconcile();
        $module = $this->manager->find('ok-module');
        $this->manager->activate($module);

        return $module;
    }

    public function test_modules_index_requires_permission(): void
    {
        $this->manager->reconcile();

        $this->actingAs($this->userWithPermissions([]))
            ->get(route('admin.modules.index'))
            ->assertForbidden();

        $this->actingAsAdminWith(['modules.view'])
            ->get(route('admin.modules.index'))
            ->assertOk()
            ->assertSee('Modules')
            ->assertSee('OK Module')
            ->assertSee('Crash Module');
    }

    public function test_activate_requires_modules_manage(): void
    {
        $this->manager->reconcile();
        $module = $this->manager->find('ok-module');

        $this->actingAs($this->userWithPermissions([]))
            ->post(route('admin.modules.activate', $module))
            ->assertForbidden();

        $this->actingAsAdminWith(['modules.manage'])
            ->from(route('admin.modules.index'))
            ->post(route('admin.modules.activate', $module))
            ->assertRedirect(route('admin.modules.index'))
            ->assertSessionHas('success');

        $this->assertSame(Module::STATUS_ACTIVE, $module->fresh()->status);
    }

    public function test_deactivate_requires_modules_manage(): void
    {
        $this->manager->reconcile();
        $module = $this->manager->find('ok-module');

        $this->actingAsAdminWith(['modules.manage'])
            ->post(route('admin.modules.activate', $module));

        $this->actingAs($this->userWithPermissions([]))
            ->post(route('admin.modules.deactivate', $module))
            ->assertForbidden();

        $this->actingAsAdminWith(['modules.manage'])
            ->from(route('admin.modules.index'))
            ->post(route('admin.modules.deactivate', $module))
            ->assertRedirect(route('admin.modules.index'))
            ->assertSessionHas('success');

        $this->assertSame(Module::STATUS_DISABLED, $module->fresh()->status);
    }

    public function test_product_module_toggle_requires_products_edit(): void
    {
        $module = $this->activatedOkModule();
        $product = $this->makeProduct();

        $this->actingAs($this->userWithPermissions([]))
            ->post(route('admin.products.modules.toggle', [$product, $module]))
            ->assertForbidden();

        $this->actingAsAdminWith(['products.edit'])
            ->post(route('admin.products.modules.toggle', [$product, $module]))
            ->assertRedirect(route('admin.products.show', [$product, 'tab' => 'modules']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('product_module', [
            'product_id' => $product->id,
            'module_id' => $module->id,
            'enabled' => 1,
        ]);
    }

    public function test_update_config_persists_encrypted(): void
    {
        $module = $this->activatedOkModule();
        $product = $this->makeProduct();

        $this->actingAsAdminWith(['products.edit'])
            ->post(route('admin.products.modules.toggle', [$product, $module]));

        $this->actingAsAdminWith(['products.edit'])
            ->from(route('admin.products.show', [$product, 'tab' => 'modules']))
            ->put(route('admin.products.modules.config', [$product, $module]), [
                'config' => ['secret' => 's3cret', 'greeting' => 'hi'],
            ])
            ->assertRedirect(route('admin.products.show', [$product, 'tab' => 'modules']))
            ->assertSessionHas('success');

        $pivot = ProductModule::where('product_id', $product->id)
            ->where('module_id', $module->id)
            ->firstOrFail();

        $this->assertSame('hi', $pivot->config['greeting']);
        $this->assertStringStartsWith('eyJ', $pivot->config['secret'], 'Secret must be stored encrypted.');

        $decrypted = $this->manager->decryptConfig($module, $pivot->config);
        $this->assertSame('s3cret', $decrypted['secret']);
        $this->assertSame('hi', $decrypted['greeting']);
    }

    public function test_product_edit_page_renders_modules_tab_with_toggle_and_config(): void
    {
        $module = $this->activatedOkModule();
        $product = $this->makeProduct();

        // Unattached: the edit page shows the module with an Enable button.
        $this->actingAsAdminWith(['products.edit'])
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('Modules', false)
            ->assertSee('OK Module')
            ->assertSee('Enable');

        // Attach it, then the edit page shows the config fields + save button.
        $this->actingAsAdminWith(['products.edit'])
            ->post(route('admin.products.modules.toggle', [$product, $module]));

        $this->actingAsAdminWith(['products.edit'])
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('OK Module')
            ->assertSee('Disable')
            ->assertSee('secret', false)
            ->assertSee('Save OK Module config');
    }

    public function test_hosting_show_page_info_tab_renders_module_data(): void
    {
        $module = $this->activatedOkModule();
        $product = $this->makeProduct();

        $account = HostingAccount::create([
            'customer_id' => 1,
            'product_id' => $product->id,
            'username' => 'acctmod1',
        ]);

        // The generic per-module "Module data" panel was removed by design
        // (hostingAccountInfo strip): the Info tab now renders read-only cards
        // only for modules implementing HostingAccountInfoProvider, and
        // ok-module is a plain provisioning module. It must contribute
        // nothing to the account page.
        $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('Package resources')
            ->assertDontSee('Module data')
            ->assertDontSee('OK Module')
            ->assertDontSee('Not enabled');

        // Attach + save config: still no card on the info tab (the only
        // "OK Module" string allowed afterwards is the save-confirmation
        // flash), and neither the decrypted values nor their field labels
        // may leak onto the page.
        $this->actingAsAdminWith(['products.edit'])
            ->post(route('admin.products.modules.toggle', [$product, $module]))
            ->assertRedirect();

        $this->actingAsAdminWith(['products.edit'])
            ->put(route('admin.products.modules.config', [$product, $module]), [
                'config' => ['greeting' => 'hello there', 'secret' => 's3cret'],
            ])
            ->assertRedirect();

        $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('Package resources')
            ->assertDontSee('Module data')
            ->assertDontSee('Greeting')
            ->assertDontSee('hello there')
            ->assertDontSee('Secret')
            ->assertDontSee('s3cret');
    }

    /**
     * Real modules own the RDP + SSH tool sections through the
     * HostingAccountToolsProvider capability: with rdp-console/ssh-console
     * active and enabled on the product, both sections render on the hosting
     * show page with their launch buttons, settings modals and the
     * assigned-IP host dropdown (public-first labels) preserved.
     *
     * The @can('hosting.view') / @can('hosting.manage') wrappers around the
     * individual controls moved VERBATIM with the markup, so the original
     * permission structure is unchanged; the app's Gate grants admin-role
     * users every ability by design (see the vendor AdminLTE Gate::before),
     * so visibility differences between permissions are enforced by route
     * middleware and cannot be asserted from rendered HTML here.
     */
    public function test_hosting_show_renders_module_owned_tools_for_active_modules(): void
    {
        // The fixture module path set by setUpModuleFixtures() hides the real
        // rdp-console / ssh-console folders — point the manager back at them.
        config(['modules.path' => base_path('modules')]);

        $manager = app(ModuleManager::class);
        $manager->reconcile();

        $modules = [];

        foreach (['ssh-console', 'rdp-console'] as $slug) {
            $module = $manager->find($slug);
            $this->assertNotNull($module, "{$slug} module must be discovered.");

            $manager->activate($module);

            $instance = $manager->resolve($module);
            $this->assertNotNull($instance, "{$slug} provider must resolve.");
            $instance->boot($manager->contextFor($module));

            $modules[$slug] = $module;
        }

        $manager->registerModuleRoutes();
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();

        $product = $this->makeProduct();

        $account = HostingAccount::create([
            'customer_id' => 1,
            'product_id' => $product->id,
            'username' => 'modtools1',
        ]);

        foreach ($modules as $module) {
            ProductModule::create([
                'product_id' => $product->id,
                'module_id' => $module->id,
                'enabled' => true,
                'config' => [],
            ]);
        }

        // A public lease gives both cards a resolved host so their launch
        // buttons and host dropdowns render.
        IpAddress::create([
            'subnet_id' => IpSubnet::create([
                'name' => 'Tools Public Subnet',
                'subnet_cidr' => '203.0.113.64/24',
            ])->id,
            'ip_address' => '203.0.113.10',
            'type' => 'public',
            'assigned_to_type' => HostingAccount::class,
            'assigned_to_id' => $account->id,
        ]);

        // Both sections render with the full admin permission set: cards,
        // view-gated launch buttons and manage-gated settings modals.
        $this->actingAsAdminWith(['hosting.view', 'hosting.manage'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('Remote Desktop (RDP)')
            ->assertSee('SSH Terminal')
            ->assertSee('Download .rdp')
            ->assertSee('Open Terminal')
            ->assertSee('(from assigned IP)', false)
            ->assertSee('Edit RDP settings')
            ->assertSee('Edit SSH settings')
            ->assertSee('id="rdp-edit-modal"', false)
            ->assertSee('id="ssh-edit-modal"', false)
            ->assertSee('<option value="">Use assigned IP</option>', false)
            ->assertSee('203.0.113.10 · Public', false);
    }

    /**
     * Failure scenario: deactivating ssh-console drops its tools section from
     * the hosting show page while the rest of the page renders normally
     * (HTTP 200, no exceptions).
     */
    public function test_hosting_show_drops_ssh_section_when_ssh_console_deactivated(): void
    {
        config(['modules.path' => base_path('modules')]);

        $manager = app(ModuleManager::class);
        $manager->reconcile();

        $module = $manager->find('ssh-console');
        $this->assertNotNull($module, 'ssh-console module must be discovered.');
        $manager->activate($module);

        $instance = $manager->resolve($module);
        $this->assertNotNull($instance, 'ssh-console provider must resolve.');
        $instance->boot($manager->contextFor($module));

        $manager->registerModuleRoutes();
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();

        $product = $this->makeProduct();

        $account = HostingAccount::create([
            'customer_id' => 1,
            'product_id' => $product->id,
            'username' => 'modtools2',
        ]);

        ProductModule::create([
            'product_id' => $product->id,
            'module_id' => $module->id,
            'enabled' => true,
            'config' => [],
        ]);

        // Sanity while active: the section is present.
        $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('SSH Terminal');

        $manager->deactivate($module);

        $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('Package resources')
            ->assertDontSee('SSH Terminal')
            ->assertDontSee('Open Terminal')
            ->assertDontSee('id="ssh-edit-modal"', false);
    }
}
