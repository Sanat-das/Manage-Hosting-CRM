<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cart/checkout add-on previews: the storefront must display the add-on lines
 * placeOrder() materializes, so the shown subtotal equals the charged total.
 */
class AddOnCartPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $name = 'Shared Hosting Basic', float $price = 100.00): Product
    {
        return Product::create([
            'name' => $name,
            'price' => $price,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
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
            'setup_fee' => 0.00,
            'status' => 'active',
        ], $attributes));
    }

    private function makeCustomerUser(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Preview Corp',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<int, array{addon_id: int, quantity: int}>  $addons
     */
    private function seedCart(Product $product, array $addons): void
    {
        session()->put('cart', [
            [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'domain' => null,
                'addons' => $addons,
            ],
        ]);
    }

    public function test_cart_page_previews_addon_lines_and_subtotal_includes_them(): void
    {
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $customer = $this->makeCustomerUser();
        $this->seedCart($product, [['addon_id' => $addon->id, 'quantity' => 2]]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.store.cart'))
            ->assertOk();

        $response->assertSee('Extra Storage');
        $response->assertSee('Add-on');
        $response->assertSee('₹50.00');    // 2 × 25 add-on line — absent without the preview
        $response->assertSee('₹150.00');   // 100 parent + 50 add-on
    }

    public function test_cart_page_previews_addon_setup_fee_line(): void
    {
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product, ['setup_fee' => 50.00]);
        $customer = $this->makeCustomerUser();
        $this->seedCart($product, [['addon_id' => $addon->id, 'quantity' => 1]]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.store.cart'))
            ->assertOk();

        $response->assertSee('Extra Storage — Setup Fee');
        $response->assertSee('₹175.00');   // 100 parent + 25 add-on + 50 setup
    }

    public function test_checkout_page_previews_addon_lines_and_grand_total_includes_them(): void
    {
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $customer = $this->makeCustomerUser();
        $this->seedCart($product, [['addon_id' => $addon->id, 'quantity' => 2]]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.store.checkout'))
            ->assertOk();

        $response->assertSee('Extra Storage');
        $response->assertSee('Add-on');
        $response->assertSee('₹50.00');    // add-on line total
        $response->assertSee('₹150.00');   // grand total includes the add-on
    }

    public function test_invalid_addon_selections_are_skipped_silently(): void
    {
        $product = $this->makeProduct();
        $otherProduct = $this->makeProduct('VPS Starter', 500.00);
        $inactive = $this->makeAddon($product, ['name' => 'Retired Storage', 'status' => 'inactive']);
        $foreign = $this->makeAddon($otherProduct, ['name' => 'Foreign Storage']);
        $customer = $this->makeCustomerUser();
        $this->seedCart($product, [
            ['addon_id' => 999999, 'quantity' => 1],
            ['addon_id' => $inactive->id, 'quantity' => 1],
            ['addon_id' => $foreign->id, 'quantity' => 1],
        ]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.store.cart'))
            ->assertOk();

        $response->assertDontSee('Retired Storage');
        $response->assertDontSee('Foreign Storage');
        $response->assertSee('₹100.00');   // only the parent line remains
    }
}
