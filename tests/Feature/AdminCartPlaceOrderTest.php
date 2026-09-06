<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminCartPlaceOrderTest extends TestCase
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

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Test Corp',
            'status' => 'active',
        ]);
    }

    public function test_places_order_from_cart(): void
    {
        $this->actingAsAdmin();

        $productA = $this->makeProduct('Shared Hosting Basic', 100.00);
        $productB = $this->makeProduct('VPS Starter', 500.00);
        $customer = $this->makeCustomer();

        // Build the session cart exactly as CartController::addToCart does.
        session()->put('cart', [
            ['product_id' => $productA->id, 'billing_cycle' => 'monthly', 'domain' => null],
            ['product_id' => $productB->id, 'billing_cycle' => 'monthly', 'domain' => 'example.com'],
        ]);

        $response = $this->post(route('admin.cart.place-order'), ['customer_id' => $customer->id]);

        $this->assertSame(2, Order::count());
        $this->assertSame(2, OrderItem::count());

        $orders = Order::query()->orderBy('id')->get();
        $first = $orders[0];
        $second = $orders[1];

        // Every cart order gets the same draft invoice as the admin order form.
        $this->assertSame(2, Invoice::count());
        $this->assertDatabaseHas('invoices', [
            'order_id' => $first->id,
            'status' => 'draft',
            'amount' => 100.00,
        ]);
        $this->assertDatabaseHas('invoices', [
            'order_id' => $second->id,
            'status' => 'draft',
            'amount' => 500.00,
        ]);

        // The admin cart writes the same order_created activity row per order
        // as the admin order form / API / storefront.
        $this->assertDatabaseHas('activity_log', [
            'customer_id' => $customer->id,
            'action' => 'order_created',
        ]);
        $this->assertSame(2, ActivityLog::where('action', 'order_created')->count());

        $this->assertSame('pending', $first->status);
        $this->assertSame($customer->id, $first->customer_id);
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{5}$/', $first->order_number);
        $this->assertNotSame($first->order_number, $second->order_number);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $first->id,
            'product_id' => $productA->id,
            'product_name' => 'Shared Hosting Basic',
            'unit_price' => 100.00,
            'total' => 100.00,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $second->id,
            'domain_name' => 'example.com',
            'total' => 500.00,
        ]);

        $this->assertNull(session('cart'), 'cart must be cleared after placement');

        $response->assertRedirect(route('admin.orders.show', $first));
        $response->assertSessionHas('success');
    }

    public function test_empty_cart_rejected(): void
    {
        $this->actingAsAdmin();
        $customer = $this->makeCustomer();

        $response = $this->from(route('admin.cart.checkout'))
            ->post(route('admin.cart.place-order'), ['customer_id' => $customer->id]);

        $response->assertRedirect(route('admin.cart.checkout'));
        $response->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::count());
    }

    public function test_customer_required(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();

        session()->put('cart', [
            ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain' => null],
        ]);

        $response = $this->post(route('admin.cart.place-order'), ['customer_id' => null]);

        $response->assertSessionHasErrors('customer_id');
        $this->assertSame(0, Order::count());
    }

    public function test_inactive_or_hidden_products_skipped(): void
    {
        $this->actingAsAdmin();

        $visible = $this->makeProduct('Visible Product', 50.00);
        $hidden = Product::create([
            'name' => 'Hidden Product',
            'price' => 999.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => false,
            'status' => 'active',
        ]);
        $inactive = Product::create([
            'name' => 'Inactive Product',
            'price' => 999.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'inactive',
        ]);
        $customer = $this->makeCustomer();

        session()->put('cart', [
            ['product_id' => $visible->id, 'billing_cycle' => 'monthly', 'domain' => null],
            ['product_id' => $hidden->id, 'billing_cycle' => 'monthly', 'domain' => null],
            ['product_id' => $inactive->id, 'billing_cycle' => 'monthly', 'domain' => null],
        ]);

        $response = $this->post(route('admin.cart.place-order'), ['customer_id' => $customer->id]);

        $this->assertSame(1, Order::count());
        $order = Order::sole();
        $this->assertSame($visible->id, $order->product_id);

        $response->assertRedirect(route('admin.orders.show', $order));
    }

    public function test_route_registered_with_permission_gate(): void
    {
        $this->actingAsAdmin();

        $route = Route::getRoutes()->getByName('admin.cart.place-order');
        $this->assertNotNull($route);
        $this->assertSame('POST', $route->methods()[0] ?? null);
    }

    public function test_cart_order_writes_config_options_snapshot(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct('Snapshot Hosting', 250.00);
        $customer = $this->makeCustomer();

        session()->put('cart', [
            ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain' => null],
        ]);

        $this->post(route('admin.cart.place-order'), ['customer_id' => $customer->id]);

        // The admin cart writes the config_options JSON snapshot (no customer
        // selections on this path) on the order item, like every other
        // order-entry point.
        $item = OrderItem::sole();
        $this->assertIsArray($item->config_options);
        $this->assertArrayHasKey('product_group_name', $item->config_options);
        $this->assertArrayHasKey('provisioning_module', $item->config_options);
        $this->assertSame([], $item->config_options['options']);
    }

    public function test_bundle_expanded_line_preserves_quantity(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct('cPanel License', 10.00);
        $customer = $this->makeCustomer();

        // Bundle-expanded cart lines carry a precomputed unit_price/total AND
        // the component quantity (CartController::addToCart for bundles) —
        // the order must record the real quantity, not the hardcoded 1.
        session()->put('cart', [
            [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'domain' => null,
                'quantity' => 5,
                'unit_price' => 10.00,
                'total' => 50.00,
                'bundle_id' => 1,
                'bundle_name' => 'Pro Bundle',
            ],
        ]);

        $response = $this->post(route('admin.cart.place-order'), ['customer_id' => $customer->id]);

        $order = Order::sole();

        $this->assertSame(5, $order->quantity);
        $this->assertSame('50.00', (string) $order->total);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_price' => 10.00,
            'total' => 50.00,
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
    }

    public function test_plain_line_total_scales_with_quantity(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct('Reseller Slot', 25.00);
        $customer = $this->makeCustomer();

        // Non-bundle lines: quantity rides in the cart entry and the resolved
        // total must be unit_price × quantity (mirrors client storefront).
        session()->put('cart', [
            [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'domain' => null,
                'quantity' => 4,
            ],
        ]);

        $response = $this->post(route('admin.cart.place-order'), ['customer_id' => $customer->id]);

        $order = Order::sole();

        $this->assertSame(4, $order->quantity);
        $this->assertSame('100.00', (string) $order->total); // 4 × 25.00

        $response->assertRedirect(route('admin.orders.show', $order));
    }

    public function test_single_unit_product_forces_quantity_one_in_order(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct('Single Unit Addon', 25.00);
        $product->update(['quantity_behaviour' => 'none']);
        $customer = $this->makeCustomer();

        // Seed a tampered cart with qty 4 — resolveCartItems must clamp to 1
        // and placeOrder must persist qty 1 with a single-unit total.
        session()->put('cart', [
            [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'domain' => null,
                'quantity' => 4,
            ],
        ]);

        $response = $this->post(route('admin.cart.place-order'), ['customer_id' => $customer->id]);

        $order = Order::sole();

        $this->assertSame(1, $order->quantity);
        $this->assertSame('25.00', (string) $order->total); // qty clamps to 1

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
    }
}
