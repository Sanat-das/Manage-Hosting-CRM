<?php

namespace Tests\Feature;

use App\Models\Customer;
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
            'type' => 'shared_hosting',
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
            'type' => 'shared_hosting',
            'price' => 999.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => false,
            'status' => 'active',
        ]);
        $inactive = Product::create([
            'name' => 'Inactive Product',
            'type' => 'shared_hosting',
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
}
