<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPricing;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductsServicesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_services_index_requires_permission(): void
    {
        // Admin-panel user WITHOUT hosting.view -> the permission gate must 403.
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $user->assignRole('admin');

        $this->actingAs($user)
            ->get(route('admin.hosting.index'))
            ->assertForbidden();

        // Admin WITH hosting.view -> 200.
        $this->actingAsAdmin()
            ->get(route('admin.hosting.index'))
            ->assertOk();
    }

    public function test_products_services_index_renders_labels(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.hosting.index'))
            ->assertOk()
            ->assertSee('Products/Services')
            ->assertSee('All Products/Services')
            ->assertSee('Billing Cycle')
            ->assertSee('Recurring Amount')
            ->assertSee('Next Due Date')
            ->assertSee('Registration Date');
    }

    public function test_products_services_index_lists_accounts(): void
    {
        $account = $this->makeAccount();

        $this->actingAsAdmin()
            ->get(route('admin.hosting.index'))
            ->assertOk()
            ->assertSee($account->domain)
            ->assertSee($account->username);
    }

    public function test_hosting_package_listing_uses_is_hosting_group_filter(): void
    {
        $this->actingAsAdmin();

        // Hosting filter (task 7): hosting packages are products whose group
        // has is_hosting = true. Products in a non-hosting group (domains,
        // addons) must NOT be offered as hosting packages.
        $hostingGroup = ProductGroup::create([
            'name' => 'Web Hosting',
            'slug' => 'web-hosting',
            'status' => 'active',
            'is_hosting' => true,
        ]);
        $nonHostingGroup = ProductGroup::create([
            'name' => 'Addons & Extras',
            'slug' => 'addons-extras',
            'status' => 'active',
            'is_hosting' => false,
        ]);

        $hostingProduct = Product::create([
            'name' => 'Hosting Package Plan',
            'product_group_id' => $hostingGroup->id,
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
        $nonHostingProduct = Product::create([
            'name' => 'Addon Extra Item',
            'product_group_id' => $nonHostingGroup->id,
            'price' => 15.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);

        // The hosting create form's package dropdown lists only products in a
        // hosting group.
        $this->get(route('admin.hosting.create'))
            ->assertOk()
            ->assertSee($hostingProduct->name)
            ->assertDontSee($nonHostingProduct->name);
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

    private function makeAccount(): HostingAccount
    {
        $user = User::factory()->create();

        $customer = Customer::create([
            'user_id' => $user->id,
            'company' => 'Test Corp',
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Shared Hosting Basic',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'active',
        ]);

        ProductPricing::create([
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'price' => 100.00,
        ]);

        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'username' => 'testacct1',
            'domain' => 'example.com',
            'status' => 'active',
        ]);
    }
}
