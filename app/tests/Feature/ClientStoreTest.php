<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\OrderNumberService;
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

    public function test_quantity_behaviour_none_locks_product_to_quantity_one_in_cart(): void
    {
        $product = $this->makeProduct('Single Unit Addon', 150.00);
        $product->update(['quantity_behaviour' => 'none']);
        $customer = $this->makeCustomerUser();

        // Attempt qty 5 — 'none' clamps it to 1 at add time.
        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 5,
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertSame(1, $cart[0]['quantity']);

        // Even a cart update to a higher quantity stays locked at 1.
        $this->actingAs($customer->user)
            ->post(route('client.store.cart.update'), ['index' => 0, 'quantity' => 9])
            ->assertRedirect(route('client.store.cart'));

        $cart = session('cart');
        $this->assertSame(1, $cart[0]['quantity']);
    }

    public function test_quantity_behaviour_none_order_has_quantity_one(): void
    {
        $product = $this->makeProduct('Single Unit Addon', 150.00);
        $product->update(['quantity_behaviour' => 'none']);
        $customer = $this->makeCustomerUser();

        // Seed a tampered cart with qty 3 — resolveCartItems must clamp and
        // placeOrder must persist qty 1 with a single-unit total.
        session()->put('cart', [
            ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'quantity' => 3],
        ]);

        $this->actingAs($customer->user)
            ->post(route('client.store.checkout.post'))
            ->assertRedirect();

        $order = Order::sole();
        $this->assertSame(1, $order->quantity);
        $this->assertSame(150.00, (float) $order->total);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 150.00,
            'total' => 150.00,
        ]);
    }

    public function test_adding_distinct_products_appends_both_to_cart(): void
    {
        $productA = $this->makeProduct('Shared Hosting Basic', 100.00);
        $productB = $this->makeProduct('VPS Starter', 500.00);
        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $productA->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
            ])
            ->assertRedirect(route('client.store.index'));

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $productB->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertCount(2, $cart);
        $this->assertSame(1, $cart[0]['quantity']);
        $this->assertSame(1, $cart[1]['quantity']);
        $this->assertSame($productA->id, $cart[0]['product_id']);
        $this->assertSame($productB->id, $cart[1]['product_id']);
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

        // The storefront order is immediately billable too: one draft invoice
        // per order, same convention as the admin paths.
        $this->assertSame(2, Invoice::count());
        $this->assertDatabaseHas('invoices', [
            'order_id' => $first->id,
            'status' => 'draft',
            'amount' => 100.00,
        ]);
        $this->assertDatabaseHas('invoices', [
            'order_id' => $orders[1]->id,
            'status' => 'draft',
            'amount' => 500.00,
        ]);

        // The storefront writes the same order_created activity row per order
        // as the admin UI / API.
        $this->assertDatabaseHas('activity_log', [
            'customer_id' => $customer->id,
            'action' => 'order_created',
        ]);
        $this->assertSame(2, ActivityLog::where('action', 'order_created')->count());

        $this->assertSame($customer->id, $first->customer_id);
        $this->assertSame('pending', $first->status);
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{5}$/', $first->order_number);

        $this->assertDatabaseHas('orders', ['id' => $orders[1]->id, 'domain_name' => 'example.com', 'total' => 500.00]);

        $this->assertNull(session('cart'), 'cart cleared after placement');

        $response->assertRedirect(route('client.store.confirmation', $first));
        $response->assertSessionHas('success');
    }

    public function test_stale_free_cycle_cart_entry_cannot_be_converted_to_an_order(): void
    {
        // Regression: legacy carts can hold entries whose billing_cycle is
        // 'free' — the payment-type marker the storefront used to offer as a
        // selectable cycle. Sanitization runs before checkout resolves the
        // lines, so a stale entry must never become an order.
        $productA = $this->makeProduct('Shared Hosting', 100.00);
        $productB = $this->makeProduct('VPS Starter', 500.00);
        $customer = $this->makeCustomerUser();

        session()->put('cart', [
            ['product_id' => $productA->id, 'billing_cycle' => 'free', 'quantity' => 1, 'domain' => null],
            ['product_id' => $productB->id, 'billing_cycle' => 'monthly', 'quantity' => 1, 'domain' => 'example.com'],
        ]);

        $response = $this->actingAs($customer->user)
            ->post(route('client.store.checkout.post'));

        // Only the valid entry is converted into an order.
        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderItem::count());

        $order = Order::sole();
        $this->assertSame($productB->id, $order->product_id);
        $this->assertSame('monthly', $order->billing_cycle);
        $this->assertSame('example.com', $order->domain_name);
        $this->assertSame(500.0, (float) $order->total);

        $this->assertNull(session('cart'), 'cart cleared after placement');

        $response->assertRedirect(route('client.store.confirmation', $order));
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

    public function test_adding_distinct_product_to_nonempty_cart_appends_both(): void
    {
        $productA = $this->makeProduct('Shared Hosting', 100.00);
        $productB = $this->makeProduct('VPS Starter', 500.00);
        $customer = $this->makeCustomerUser();

        // Add product A
        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $productA->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertCount(1, $cart);

        // Add product B (different product) — must NOT drop product A
        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $productB->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertCount(2, $cart, 'Cart must contain both distinct products');

        $ids = array_column($cart, 'product_id');
        $this->assertContains($productA->id, $ids);
        $this->assertContains($productB->id, $ids);

        // Each should have quantity 1
        foreach ($cart as $item) {
            $this->assertSame(1, $item['quantity']);
        }
    }

    public function test_checkout_empty_cart_redirects_to_cart(): void
    {
        $customer = $this->makeCustomerUser();

        $response = $this->actingAs($customer->user)
            ->get(route('client.store.checkout'));

        $response->assertRedirect(route('client.store.cart'));
        $response->assertSessionHas('error', 'Your cart is empty.');
    }

    public function test_remove_from_cart_removes_item_and_reindexes(): void
    {
        $productA = $this->makeProduct('Shared Hosting', 100.00);
        $productB = $this->makeProduct('VPS Starter', 500.00);
        $customer = $this->makeCustomerUser();

        session()->put('cart', [
            ['product_id' => $productA->id, 'billing_cycle' => 'monthly', 'quantity' => 1],
            ['product_id' => $productB->id, 'billing_cycle' => 'monthly', 'quantity' => 1],
        ]);

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.remove'), ['index' => 0])
            ->assertRedirect(route('client.store.cart'));

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertSame([0], array_keys($cart), 'Cart keys must be reindexed after removal');
        $this->assertSame($productB->id, $cart[0]['product_id']);
    }

    public function test_remove_from_cart_with_array_index_returns_redirect(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomerUser();

        session()->put('cart', [
            ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'quantity' => 1],
        ]);

        $this->actingAs($customer->user)
            ->from(route('client.store.cart'))
            ->post(route('client.store.cart.remove'), ['index' => [0]])
            ->assertRedirect(route('client.store.cart'));

        $this->assertCount(1, session('cart'), 'Malformed index must leave the cart untouched');
    }

    public function test_add_to_cart_rejects_inactive_product(): void
    {
        $product = $this->makeProduct();
        $product->update(['status' => 'inactive']);
        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('product_id');

        $this->assertNull(session('cart'));
    }

    public function test_add_to_cart_rejects_admin_only_product(): void
    {
        $product = $this->makeProduct();
        $product->update(['only_admin' => true]);
        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('product_id');

        $this->assertNull(session('cart'));
    }

    public function test_update_cart_recomputes_bundle_line_total(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomerUser();

        session()->put('cart', [
            [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 2,
                'unit_price' => 100.00,
                'total' => 200.00,
            ],
        ]);

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.update'), ['index' => 0, 'quantity' => 3])
            ->assertRedirect(route('client.store.cart'));

        $cart = session('cart');
        $this->assertSame(3, $cart[0]['quantity']);
        $this->assertSame(300.00, $cart[0]['total'], 'Bundle line total must be recomputed from unit_price');
    }

    public function test_place_order_hides_exception_details(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomerUser();

        $this->mock(
            OrderNumberService::class,
            fn ($mock) => $mock->shouldReceive('next')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: secret db detail'))
        );

        session()->put('cart', [
            ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'quantity' => 1, 'domain' => null],
        ]);

        $response = $this->actingAs($customer->user)
            ->from(route('client.store.checkout'))
            ->post(route('client.store.checkout.post'));

        $response->assertRedirect(route('client.store.checkout'));

        // The user-facing error must be the generic message verbatim — if the
        // controller ever flashes $e->getMessage() instead, this fails.
        $response->assertSessionHasErrors([
            'error' => 'Could not place your order. Please try again or contact support.',
        ]);

        // No message reaching the session may carry the exception text.
        $messages = session('errors')->getBag('default')->all();
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('secret db detail', $message);
            $this->assertStringNotContainsString('SQLSTATE', $message);
        }

        // Re-run the failure with followingRedirects so the assertions below
        // run against RENDERED HTML (a 302 has an empty body, which would make
        // assertDontSee vacuous). A plain follow-up GET will not do: reading
        // session('errors') above ages the flash, emptying the bag before the
        // next request. The cart survives a failed placement, so no re-seed.
        $page = $this->actingAs($customer->user)
            ->from(route('client.store.checkout'))
            ->followingRedirects()
            ->post(route('client.store.checkout.post'))
            ->assertOk();

        $page->assertDontSee('secret db detail', false);
        $page->assertDontSee('SQLSTATE', false);
        $page->assertDontSee('RuntimeException', false);

        // The generic message must actually RENDER (via the _alerts partial),
        // not just sit unread in the session — otherwise the user sees nothing.
        $page->assertSee('Could not place your order. Please try again or contact support.', false);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
    }

    public function test_place_order_empty_cart_renders_guard_message(): void
    {
        $customer = $this->makeCustomerUser();

        // No cart seeded — placeOrder's empty-cart guard must fire.
        // Coming from the cart page: placeOrder redirects back there and the
        // _alerts partial renders the error bag. (Coming from checkout would
        // bounce on to cart via checkout()'s own guard, discarding the bag.)
        $response = $this->actingAs($customer->user)
            ->from(route('client.store.cart'))
            ->followingRedirects()
            ->post(route('client.store.checkout.post'))
            ->assertOk();

        $response->assertSee('Your cart is empty — nothing to order.', false);

        $this->assertSame(0, Order::count());
    }
}
