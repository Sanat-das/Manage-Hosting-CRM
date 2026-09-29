<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin cart checkout preview (t23): the add-on selections riding on a cart
 * entry are billed at order time by AddOnService::materialize(), so the
 * checkout summary must expand the same recurring + setup lines — otherwise
 * the operator approves a total below what the customer is charged.
 */
class AddOnAdminCartPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $create = Permission::firstOrCreate(['name' => 'orders.create'], ['label' => 'Create Orders']);
        $adminRole->permissions()->syncWithoutDetaching([$create->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeProduct(string $name = 'Shared Hosting Basic', float $price = 100.00): Product
    {
        return Product::create([
            'name' => $name,
            'price' => $price,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'active',
        ]);
    }

    private function makeAddon(Product $product, array $attributes = []): ProductAddon
    {
        return ProductAddon::create(array_merge([
            'product_id' => $product->id,
            'name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
            'price' => 25.00,
            'setup_fee' => 50.00,
            'status' => 'active',
        ], $attributes));
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Preview Corp',
            'status' => 'active',
        ]);
    }

    public function test_checkout_preview_shows_addon_lines_and_the_billed_subtotal(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);

        // Same payload the operator's add-to-cart form posts.
        $this->post(route('admin.cart.add'), [
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'addons' => [
                ['addon_id' => $addon->id, 'quantity' => 1],
            ],
        ])->assertRedirect(route('admin.cart.index'));

        $cart = session('cart');
        $this->assertSame([['addon_id' => $addon->id, 'quantity' => 1]], $cart[0]['addons']);

        $response = $this->get(route('admin.cart.checkout'));
        $response->assertOk();

        // The recurring add-on line and its setup fee are rendered, with the
        // "Add-on" badge on the preview rows.
        $response->assertSee('Extra Storage');
        $response->assertSee('₹25.00');
        $response->assertSee('Extra Storage — Setup Fee');
        $response->assertSee('₹50.00');
        $response->assertSee('Add-on');

        // The summary subtotal equals what placeOrder() charges: 100 + 25 + 50.
        $response->assertSee('₹175.00');

        // Preview rows carry no remove action — only the parent line does.
        $this->assertSame(1, substr_count($response->getContent(), route('admin.cart.remove')));

        // The operator-approved amount is the amount billed.
        $customer = $this->makeCustomer();
        $this->post(route('admin.cart.place-order'), ['customer_id' => $customer->id])
            ->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame(175.00, round((float) $order->fresh()->total, 2));
        $this->assertSame(3, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_checkout_preview_untouched_without_addon_selections(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();

        $this->post(route('admin.cart.add'), [
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
        ])->assertRedirect(route('admin.cart.index'));

        $response = $this->get(route('admin.cart.checkout'));
        $response->assertOk();

        $response->assertSee('₹100.00');
        $response->assertDontSee('Add-on');
        $this->assertSame(1, substr_count($response->getContent(), route('admin.cart.remove')));
    }
}
