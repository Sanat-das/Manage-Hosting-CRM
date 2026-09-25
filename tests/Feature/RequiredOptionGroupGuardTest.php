<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequiredOptionGroupGuardTest extends TestCase
{
    use RefreshDatabase;

    private function adminWith(array $perms): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $ids = [];
        foreach ($perms as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $role->permissions()->sync($ids);
        $user->assignRole('admin');

        return $user;
    }

    private function makeGroup(string $name, string $key, string $type = 'number'): ProductOptionGroup
    {
        return ProductOptionGroup::create([
            'name' => $name.' '.uniqid(),
            'key' => $key,
            'type' => $type,
        ]);
    }

    private function makeProduct(string $provisioningModule = 'hyperv'): Product
    {
        return Product::create([
            'name' => 'Guard Product '.uniqid(),
            'price' => 50,
            'provisioning_module' => $provisioningModule,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
    }

    private function attachGroup(Product $product, ProductOptionGroup $group): ProductOptionGroupProduct
    {
        $link = app(\App\Services\ProductOptionLinkService::class)->attachGroup($product, $group);
        $this->assertNotNull($link);

        return $link;
    }

    public function test_destroy_cpu_group_is_blocked(): void
    {
        $group = $this->makeGroup('CPU', 'cpu');

        $this->actingAs($this->adminWith(['products.options']))
            ->from(route('admin.product-options.index'))
            ->delete(route('admin.product-options.destroy', $group))
            ->assertSessionHasErrors('error');

        $this->assertDatabaseHas('product_option_groups', ['id' => $group->id]);
    }

    public function test_destroy_plan_group_is_blocked(): void
    {
        $group = $this->makeGroup('Plan', 'plan', 'dropdown');

        $this->actingAs($this->adminWith(['products.options']))
            ->from(route('admin.product-options.index'))
            ->delete(route('admin.product-options.destroy', $group))
            ->assertSessionHasErrors('error');

        $this->assertDatabaseHas('product_option_groups', ['id' => $group->id]);
    }

    public function test_destroy_non_required_group_succeeds(): void
    {
        $group = $this->makeGroup('Color', 'color', 'dropdown');

        $this->actingAs($this->adminWith(['products.options']))
            ->from(route('admin.product-options.index'))
            ->delete(route('admin.product-options.destroy', $group))
            ->assertRedirect(route('admin.product-options.index'));

        $this->assertDatabaseMissing('product_option_groups', ['id' => $group->id]);
    }

    public function test_detach_required_cpu_link_is_blocked(): void
    {
        $product = $this->makeProduct('hyperv');
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'provisioning_mode' => ProductModule::PROVISIONING_MODE_MANUAL,
            'config' => [],
        ]);
        $group = $this->makeGroup('CPU', 'cpu');
        $link = $this->attachGroup($product, $group);

        $this->actingAs($this->adminWith(['products.edit']))
            ->from(route('admin.products.edit', $product))
            ->delete(route('admin.products.options.detach', [$product, $link]))
            ->assertSessionHasErrors('error');

        $this->assertDatabaseHas('product_option_group_product', ['id' => $link->id]);
    }

    public function test_detach_non_required_link_succeeds(): void
    {
        $product = $this->makeProduct('hyperv');
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'provisioning_mode' => ProductModule::PROVISIONING_MODE_MANUAL,
            'config' => [],
        ]);
        $group = $this->makeGroup('Color', 'color', 'dropdown');
        $link = $this->attachGroup($product, $group);

        $this->actingAs($this->adminWith(['products.edit']))
            ->from(route('admin.products.edit', $product))
            ->delete(route('admin.products.options.detach', [$product, $link]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('product_option_group_product', ['id' => $link->id]);
    }
}
