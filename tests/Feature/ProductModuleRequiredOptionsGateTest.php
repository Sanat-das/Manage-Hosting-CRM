<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProductOptionGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use App\Services\ProductOptionLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithModuleFixtures;
use Tests\TestCase;

/**
 * Enabling a provisioning module on a product requires the product to have
 * the module's required Configuration Option groups attached.
 */
class ProductModuleRequiredOptionsGateTest extends TestCase
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

    private function makeProduct(string $name = 'Gate Product'): Product
    {
        return Product::create([
            'name' => $name.' '.uniqid(),
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

    private function attachGroupKey(Product $product, string $key): void
    {
        $group = ProductOptionGroup::create([
            'name' => ucfirst($key).' Group '.uniqid(),
            'key' => $key,
            'type' => 'number',
        ]);

        app(ProductOptionLinkService::class)->attachGroup($product, $group);
    }

    public function test_enable_hyperv_blocked_without_option_groups(): void
    {
        $product = $this->makeProduct('HyperV Blocked');

        $this->actingAsAdminWith(['products.edit'])
            ->from(route('admin.products.show', [$product, 'tab' => 'modules']))
            ->post(route('admin.products.modules.toggle', [$product, 'hyperv']))
            ->assertSessionHasErrors('module');

        $this->assertDatabaseMissing('product_module', [
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => 1,
        ]);
    }

    public function test_enable_hyperv_succeeds_with_cpu_ram_disk(): void
    {
        $product = $this->makeProduct('HyperV Allowed');

        foreach (['cpu', 'ram', 'disk'] as $key) {
            $this->attachGroupKey($product, $key);
        }

        $this->actingAsAdminWith(['products.edit'])
            ->post(route('admin.products.modules.toggle', [$product, 'hyperv']))
            ->assertRedirect(route('admin.products.show', [$product, 'tab' => 'modules']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('product_module', [
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => 1,
        ]);
    }

    public function test_module_without_required_keys_toggles_without_options(): void
    {
        $module = $this->activatedOkModule();
        $product = $this->makeProduct('Ok Module');

        $this->actingAsAdminWith(['products.edit'])
            ->post(route('admin.products.modules.toggle', [$product, $module->slug]))
            ->assertRedirect(route('admin.products.show', [$product, 'tab' => 'modules']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('product_module', [
            'product_id' => $product->id,
            'module_slug' => $module->slug,
            'enabled' => 1,
        ]);
    }

    public function test_enable_cpanel_blocked_without_plan(): void
    {
        $product = $this->makeProduct('cPanel Blocked');

        $this->actingAsAdminWith(['products.edit'])
            ->from(route('admin.products.show', [$product, 'tab' => 'modules']))
            ->post(route('admin.products.modules.toggle', [$product, 'cpanel']))
            ->assertSessionHasErrors('module');

        $this->assertDatabaseMissing('product_module', [
            'product_id' => $product->id,
            'module_slug' => 'cpanel',
            'enabled' => 1,
        ]);
    }

    public function test_enable_cpanel_succeeds_with_plan(): void
    {
        $product = $this->makeProduct('cPanel Allowed');
        $this->attachGroupKey($product, 'plan');

        $this->actingAsAdminWith(['products.edit'])
            ->post(route('admin.products.modules.toggle', [$product, 'cpanel']))
            ->assertRedirect(route('admin.products.show', [$product, 'tab' => 'modules']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('product_module', [
            'product_id' => $product->id,
            'module_slug' => 'cpanel',
            'enabled' => 1,
        ]);
    }
}
