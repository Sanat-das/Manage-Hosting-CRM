<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientStoreTest extends TestCase
{
    use RefreshDatabase;

    private function makeGroup(string $name = 'Web Hosting'): ProductGroup
    {
        return ProductGroup::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'status' => 'active',
        ]);
    }

    private function makeProduct(string $name = 'Shared Hosting Basic', float $price = 100.00, ?int $groupId = null): Product
    {
        return Product::create([
            'name' => $name,
            'type' => 'shared_hosting',
            'product_group_id' => $groupId,
            'price' => $price,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ]);
    }

    private function makeCustomerUser(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Store Corp',
            'status' => 'active',
        ]);
    }

    public function test_browse_store_index_shows_orderable_products(): void
    {
        $group = $this->makeGroup();
        $product = $this->makeProduct('Shared Hosting Basic', 100.00, $group->id);
        $hidden = Product::create([
            'name' => 'Admin Only',
            'type' => 'addon',
            'product_group_id' => $group->id,
            'price' => 10,
            'billing_cycle' => 'monthly',
            'show_in_order' => false,
            'only_admin' => true,
            'status' => 'active',
        ]);

        $customer = $this->makeCustomerUser();

        $response = $this->actingAs($customer->user)
            ->get(route('client.store.index'))
            ->assertOk();

        $response->assertSee($product->name);
        $response->assertDontSee('Admin Only');
    }

    public function test_show_returns_404_for_hidden_or_inactive(): void
    {
        $hidden = Product::create([
            'name' => 'Secret',
            'type' => 'addon',
            'price' => 10,
            'billing_cycle' => 'monthly',
            'show_in_order' => false,
            'only_admin' => true,
            'status' => 'active',
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)->get(route('client.store.show', $hidden))->assertNotFound();
    }

    public function test_client_adds_item_to_cart_and_updates_quantity(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 2,
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertSame(2, $cart[0]['quantity']);

        $response = $this->actingAs($customer->user)
            ->get(route('client.store.cart'))
            ->assertOk()
            ->assertSee('₹200.00')      // 2 × 100
            ->assertSee('Subtotal');
    }

    public function test_client_places_order_creates_pending_order(): void
    {
        $productA = $this->makeProduct('Shared Hosting', 100.00);
        $productB = $this->makeProduct('VPS Starter', 500.00);
        $customer = $this->makeCustomerUser();

        session()->put('cart', [
            ['product_id' => $productA->id, 'billing_cycle' => 'monthly', 'quantity' => 1, 'domain' => null],
            ['product_id' => $productB->id, 'billing_cycle' => 'monthly', 'quantity' => 1, 'domain' => 'example.com'],
        ]);

        $response = $this->actingAs($customer->user)
            ->post(route('client.store.checkout.post'));

        $this->assertSame(2, Order::count());
        $this->assertSame(2, OrderItem::count());

        $orders = Order::query()->orderBy('id')->get();
        $first = $orders[0];

        $this->assertSame($customer->id, $first->customer_id);
        $this->assertSame('pending', $first->status);
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{5}$/', $first->order_number);

        $this->assertDatabaseHas('orders', ['id' => $orders[1]->id, 'domain_name' => 'example.com', 'total' => 500.00]);

        $this->assertNull(session('cart'), 'cart cleared after placement');

        $response->assertRedirect(route('client.store.confirmation', $first));
        $response->assertSessionHas('success');
    }

    public function test_confirmation_shows_order_and_clears_cart(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomerUser();

        $expected = str_pad((string) random_int(100, 99999), 5, '0', STR_PAD_LEFT);
        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.$expected,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
        ]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.store.confirmation', $order))
            ->assertOk();

        $response->assertSee($order->order_no);
        $response->assertSee($product->name);
    }

    public function test_client_cannot_view_another_customers_order(): void
    {
        $product = $this->makeProduct();
        $owner = $this->makeCustomerUser();
        $other = $this->makeCustomerUser();

        $order = Order::create([
            'customer_id' => $owner->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-01111',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
        ]);

        $this->actingAs($other->user)
            ->get(route('client.store.confirmation', $order))
            ->assertForbidden();
    }

    public function test_place_order_without_customer_redirects_back_with_error(): void
    {
        $product = $this->makeProduct();
        $user = User::factory()->create(); // no linked Customer record

        session()->put('cart', [
            ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'quantity' => 1, 'domain' => null],
        ]);

        $response = $this->actingAs($user)
            ->post(route('client.store.checkout.post'));

        // The customer.record middleware intercepts before the controller runs
        // and parks the client on the dashboard (account pending setup).
        $response->assertRedirect(route('client.dashboard'));
        $this->assertSame(0, Order::count());
    }
}
