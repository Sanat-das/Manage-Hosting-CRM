<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkValue;
use App\Models\ProductOptionLinkValuePricing;
use App\Models\ProductOptionPricing;
use App\Models\ProductOptionValue;
use App\Models\ProductPricing;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Order config snapshot immutability: the order item's config_options JSON is
 * captured at order time and must never change when the product's link values,
 * catalog group values or price modifiers are edited afterwards. The hosting
 * pages (admin + client) render the snapshot; the product edit page renders
 * the live (new) values.
 */
class ProductSnapshotImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private ProductOptionGroupProduct $link;

    private ProductOptionGroup $group;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin();
        $this->product = $this->makeProduct();
        [$this->group, $this->link] = $this->attachEditableLink();
        $this->customer = $this->makeCustomerUser();
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['products.view', 'products.edit', 'hosting.view'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    private function makeProduct(): Product
    {
        $group = ProductGroup::create([
            'name' => 'Web Hosting',
            'slug' => 'web-hosting',
            'status' => 'active',
            'is_hosting' => true,
        ]);

        $product = Product::create([
            'name' => 'Immutable Hosting',
            'product_group_id' => $group->id,
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'provisioning_module' => 'manual',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
            'require_domain' => false,
        ]);

        ProductPricing::create([
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'price' => 100.00,
            'setup_fee' => 0,
        ]);

        return $product;
    }

    /**
     * Attach an editable dropdown link whose two values carry distinct
     * per-cycle price modifiers.
     *
     * @return array{0: ProductOptionGroup, 1: ProductOptionGroupProduct}
     */
    private function attachEditableLink(): array
    {
        $group = ProductOptionGroup::create([
            'name' => 'Support Level',
            'sort_order' => 1,
            'type' => 'dropdown',
        ]);

        $definitions = [
            ['label' => 'Standard Support', 'modifier' => 0.0],
            ['label' => 'Priority Support', 'modifier' => 149.0],
        ];

        foreach ($definitions as $sort => $definition) {
            $value = ProductOptionValue::create([
                'option_group_id' => $group->id,
                'label' => $definition['label'],
                'sort_order' => $sort + 1,
            ]);

            ProductOptionPricing::create([
                'option_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $definition['modifier'],
            ]);
        }

        $link = ProductOptionGroupProduct::create([
            'product_id' => $this->product->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        foreach ($definitions as $sort => $definition) {
            $linkValue = ProductOptionLinkValue::create([
                'product_option_group_product_id' => $link->id,
                'label' => $definition['label'],
                'is_default' => $sort === 0,
                'sort_order' => $sort + 1,
            ]);

            ProductOptionLinkValuePricing::create([
                'product_option_link_value_id' => $linkValue->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $definition['modifier'],
            ]);
        }

        return [$group, $link];
    }

    private function makeCustomerUser(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Immutable Corp',
            'status' => 'active',
        ]);
    }

    private function placeOrderWithPrioritySupport(): Order
    {
        session()->put('cart', [[
            'product_id' => $this->product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'options' => [$this->link->id => 'Priority Support'],
            'unit_price' => 249.00,
            'total' => 249.00,
        ]]);

        $this->actingAs($this->customer->user)
            ->post(route('client.store.checkout.post'))
            ->assertRedirect();

        return Order::sole();
    }

    public function test_config_options_snapshot_is_immutable_after_catalog_edits(): void
    {
        $order = $this->placeOrderWithPrioritySupport();
        $item = OrderItem::sole();

        $snapshot = $item->config_options;
        $this->assertSame('Web Hosting', $snapshot['product_group_name']);
        $this->assertSame('manual', $snapshot['provisioning_module']);
        $this->assertCount(1, $snapshot['options']);

        $option = $snapshot['options'][0];
        $this->assertSame($this->link->id, $option['id']);
        $this->assertSame('Support Level', $option['group']);
        $this->assertSame('dropdown', $option['type']);
        $this->assertTrue($option['customer_editable']);
        $this->assertSame(['Standard Support', 'Priority Support'], $option['values']);
        $this->assertSame('Priority Support', $option['selected']);

        // Mutate the live catalog: link value label, catalog group value label
        // and the link value's price modifier all change after the order.
        $linkValue = ProductOptionLinkValue::query()
            ->where('product_option_group_product_id', $this->link->id)
            ->where('label', 'Priority Support')
            ->firstOrFail();
        $linkValue->update(['label' => 'Platinum Support']);

        $groupValue = ProductOptionValue::query()
            ->where('option_group_id', $this->group->id)
            ->where('label', 'Priority Support')
            ->firstOrFail();
        $groupValue->update(['label' => 'Platinum Support']);

        ProductOptionLinkValuePricing::query()
            ->where('product_option_link_value_id', $linkValue->id)
            ->where('billing_cycle', 'monthly')
            ->update(['price_modifier' => 999.00]);

        // The live catalog changed…
        $this->assertSame('Platinum Support', $linkValue->fresh()->label);
        $this->assertSame('999.00', (string) $linkValue->fresh()->pricing()->where('billing_cycle', 'monthly')->value('price_modifier'));

        // …but the order snapshot is untouched.
        $snapshot = $item->fresh()->config_options;
        $option = $snapshot['options'][0];
        $this->assertSame(['Standard Support', 'Priority Support'], $option['values']);
        $this->assertSame('Priority Support', $option['selected']);
        $this->assertSame('Support Level', $option['group']);
        $this->assertSame('Web Hosting', $snapshot['product_group_name']);
    }

    public function test_hosting_pages_render_snapshot_and_product_edit_shows_new_values(): void
    {
        $order = $this->placeOrderWithPrioritySupport();

        $account = HostingAccount::create([
            'customer_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'order_id' => $order->id,
            'username' => 'immutable1',
            'domain' => 'immutable.example.com',
            'status' => 'active',
        ]);

        // Mutate the live catalog after the snapshot was captured.
        $linkValue = ProductOptionLinkValue::query()
            ->where('product_option_group_product_id', $this->link->id)
            ->where('label', 'Priority Support')
            ->firstOrFail();
        $linkValue->update(['label' => 'Platinum Support']);

        $this->assertSame('Platinum Support', $linkValue->fresh()->label, 'sanity: live catalog changed');

        // Admin hosting show renders the OLD snapshot values.
        $this->actingAs($this->admin)
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->assertSee('Support Level')
            ->assertSee('Priority Support')
            ->assertDontSee('Platinum Support');

        // Client hosting show renders the OLD snapshot values too.
        $this->actingAs($this->customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->assertSee('Support Level')
            ->assertSee('Priority Support')
            ->assertDontSee('Platinum Support');

        // The product edit page still shows the NEW live values.
        $this->actingAs($this->admin)
            ->get(route('admin.products.edit', $this->product))
            ->assertOk()
            ->assertSee('Platinum Support')
            ->assertDontSee('Priority Support');
    }
}
