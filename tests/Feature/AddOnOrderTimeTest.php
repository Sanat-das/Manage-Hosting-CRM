<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductAddonPricing;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Order-time add-on selection (T1.4): every order-creation entry point
 * accepts optional add-on selections and materializes them as their own
 * order items BEFORE the draft invoice is created.
 */
class AddOnOrderTimeTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting Basic',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
            'require_domain' => false,
        ], $attributes));
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
            'company' => 'Addon Corp',
            'status' => 'active',
        ]);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['orders.view', 'orders.create', 'orders.edit'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    private function apiUser(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole('admin');

        return $user;
    }

    public function test_storefront_add_to_cart_with_addons_places_parent_plus_addon_and_setup_rows(): void
    {
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'addons' => [
                    ['addon_id' => $addon->id, 'quantity' => 2],
                ],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertSame([
            ['addon_id' => $addon->id, 'quantity' => 2],
        ], $cart[0]['addons']);

        $this->actingAs($customer->user)
            ->post(route('client.store.checkout.post'))
            ->assertRedirect();

        $order = Order::sole();
        $this->assertSame(3, OrderItem::where('order_id', $order->id)->count());

        $parent = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();
        $this->assertSame($product->id, $parent->product_id);
        $this->assertNull($parent->parent_item_id);

        // Recurring add-on row: frozen field values.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Extra Storage',
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'unit_price' => 25.00,
            'total' => 50.00,
        ]);

        // Setup-fee row: own one_time line, billed once.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Extra Storage — Setup Fee',
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'unit_price' => 50.00,
            'total' => 50.00,
        ]);

        // The draft invoice was created after the add-on rows, so it carries
        // the add-on line.
        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame('draft', $invoice->status);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'Extra Storage — Monthly',
            'quantity' => 2,
            'unit_price' => 25.00,
            'total' => 50.00,
        ]);

        // Totals agree: order total = sum of rows (100 + 50 + 50), the
        // invoice amount equals that sum, and the invoice total is
        // amount + tax − discount.
        $expectedTotal = 200.00;
        $this->assertSame($expectedTotal, round((float) $order->fresh()->total, 2));
        $this->assertSame($expectedTotal, round((float) OrderItem::where('order_id', $order->id)->sum('total'), 2));
        $this->assertSame($expectedTotal, round((float) $invoice->amount, 2));
        $this->assertSame(
            round((float) $invoice->amount + (float) $invoice->tax - (float) $invoice->discount, 2),
            round((float) $invoice->total, 2)
        );
        $this->assertSame($expectedTotal, round((float) $invoice->items()->sum('total'), 2));
    }

    public function test_storefront_bills_the_addon_on_the_parents_cycle_when_the_matrix_prices_it(): void
    {
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product, [
            'billing_cycle' => 'annual',
            'price' => 250.00,
            'setup_fee' => 0,
        ]);
        ProductAddonPricing::create([
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'price' => 30.00,
            'setup_fee' => 0,
        ]);
        $customer = $this->makeCustomer();

        // Payload carries only addon_id + quantity; the server resolves the
        // cycle and the price.
        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'addons' => [
                    ['addon_id' => $addon->id, 'quantity' => 2],
                ],
            ])
            ->assertRedirect(route('client.store.index'));

        $this->actingAs($customer->user)
            ->post(route('client.store.checkout.post'))
            ->assertRedirect();

        $order = Order::sole();
        $parent = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();
        $this->assertSame('monthly', $parent->billing_cycle);

        // The add-on follows the parent's monthly cycle at the matrix price,
        // not its own annual cycle / ₹250 price, and bills no setup fee.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'unit_price' => 30.00,
            'total' => 60.00,
        ]);
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->count());

        // The draft invoice line reflects the resolved cycle and price.
        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'description' => 'Extra Storage — Monthly',
            'quantity' => 2,
            'unit_price' => 30.00,
            'total' => 60.00,
        ]);

        $expectedTotal = 160.00;
        $this->assertSame($expectedTotal, round((float) $order->fresh()->total, 2));
        $this->assertSame($expectedTotal, round((float) $invoice->amount, 2));
        $this->assertSame($expectedTotal, round((float) $invoice->items()->sum('total'), 2));
    }

    public function test_storefront_cart_entries_with_different_addons_do_not_merge(): void
    {
        $product = $this->makeProduct();
        $addonA = $this->makeAddon($product, ['name' => 'Addon A', 'setup_fee' => 0]);
        $addonB = $this->makeAddon($product, ['name' => 'Addon B', 'setup_fee' => 0]);
        $customer = $this->makeCustomer();

        $add = fn (ProductAddon $addon) => $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'addons' => [['addon_id' => $addon->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('client.store.index'));

        $add($addonA);
        $add($addonB);

        $cart = session('cart');
        $this->assertCount(2, $cart);

        // Same add-on set merges; a different set stays separate.
        $add($addonA);

        $cart = session('cart');
        $this->assertCount(2, $cart);
        $this->assertSame(2, $cart[0]['quantity']);
        $this->assertSame(1, $cart[1]['quantity']);
    }

    public function test_admin_order_form_creates_addon_rows_and_invoice_line(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'lines' => [[
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'unit_price' => 100.00,
                'addons' => [
                    ['addon_id' => $addon->id, 'quantity' => 1],
                ],
            ]],
            'generate_invoice' => 1,
        ]);

        $order = Order::sole();
        $response->assertRedirect(route('admin.orders.show', $order));

        $parent = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_name' => 'Extra Storage',
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_name' => 'Extra Storage — Setup Fee',
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'one_time',
        ]);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'description' => 'Extra Storage — Monthly',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
        ]);

        // Totals agree: order total = sum of rows (100 + 25 + 50) and the
        // invoice amount/total follow amount + tax − discount.
        $expectedTotal = 175.00;
        $this->assertSame($expectedTotal, round((float) $order->fresh()->total, 2));
        $this->assertSame($expectedTotal, round((float) OrderItem::where('order_id', $order->id)->sum('total'), 2));
        $this->assertSame($expectedTotal, round((float) $invoice->amount, 2));
        $this->assertSame(
            round((float) $invoice->amount + (float) $invoice->tax - (float) $invoice->discount, 2),
            round((float) $invoice->total, 2)
        );
        $this->assertSame($expectedTotal, round((float) $invoice->items()->sum('total'), 2));
    }

    public function test_admin_cart_creates_addon_rows_and_matching_invoice(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);

        $this->actingAs($admin)
            ->post(route('admin.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['addon_id' => $addon->id, 'quantity' => 1],
                ],
            ])
            ->assertRedirect(route('admin.cart.index'));

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertSame([
            ['addon_id' => $addon->id, 'quantity' => 1],
        ], $cart[0]['addons']);

        $response = $this->actingAs($admin)
            ->post(route('admin.cart.place-order'), ['customer_id' => $customer->id]);

        $order = Order::sole();
        $response->assertRedirect(route('admin.orders.show', $order));

        $parent = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_name' => 'Extra Storage',
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_name' => 'Extra Storage — Setup Fee',
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'unit_price' => 50.00,
            'total' => 50.00,
        ]);

        // Totals agree: order total = sum of rows (100 + 25 + 50) and the
        // invoice amount/total follow amount + tax − discount.
        $expectedTotal = 175.00;
        $this->assertSame($expectedTotal, round((float) $order->fresh()->total, 2));
        $this->assertSame($expectedTotal, round((float) OrderItem::where('order_id', $order->id)->sum('total'), 2));

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame('draft', $invoice->status);
        $this->assertSame($expectedTotal, round((float) $invoice->amount, 2));
        $this->assertSame(
            round((float) $invoice->amount + (float) $invoice->tax - (float) $invoice->discount, 2),
            round((float) $invoice->total, 2)
        );
        $this->assertSame($expectedTotal, round((float) $invoice->items()->sum('total'), 2));
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'description' => 'Extra Storage — Monthly',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
        ]);
    }

    public function test_api_creates_addon_rows(): void
    {
        $user = $this->apiUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'lines' => [[
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'unit_price' => 100.00,
                'addons' => [
                    ['addon_id' => $addon->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertStatus(201);

        $order = Order::sole();
        $parent = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_name' => 'Extra Storage',
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
        ]);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'description' => 'Extra Storage — Monthly',
        ]);

        // Totals agree: order total = sum of rows (100 + 25 + 50) and the
        // invoice amount/total follow amount + tax − discount.
        $expectedTotal = 175.00;
        $this->assertSame($expectedTotal, round((float) $order->fresh()->total, 2));
        $this->assertSame($expectedTotal, round((float) OrderItem::where('order_id', $order->id)->sum('total'), 2));
        $this->assertSame($expectedTotal, round((float) $invoice->amount, 2));
        $this->assertSame(
            round((float) $invoice->amount + (float) $invoice->tax - (float) $invoice->discount, 2),
            round((float) $invoice->total, 2)
        );
        $this->assertSame($expectedTotal, round((float) $invoice->items()->sum('total'), 2));
    }

    public function test_admin_order_form_rejects_inactive_addon(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product, ['status' => 'inactive']);

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'lines' => [[
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'unit_price' => 100.00,
                'addons' => [
                    ['addon_id' => $addon->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertSessionHasErrors('lines.0.addons.0.addon_id');
        $this->assertSame(0, Order::count());
    }

    public function test_admin_order_form_rejects_addon_scoped_to_another_product(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $other = $this->makeProduct(['name' => 'VPS Starter', 'price' => 500.00]);
        $addon = $this->makeAddon($other);

        $response = $this->actingAs($admin)->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'lines' => [[
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'unit_price' => 100.00,
                'addons' => [
                    ['addon_id' => $addon->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertSessionHasErrors('lines.0.addons.0.addon_id');
        $this->assertSame(0, Order::count());
    }

    public function test_api_rejects_addon_scoped_to_another_product(): void
    {
        $user = $this->apiUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $other = $this->makeProduct(['name' => 'VPS Starter', 'price' => 500.00]);
        $addon = $this->makeAddon($other);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'lines' => [[
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'unit_price' => 100.00,
                'addons' => [
                    ['addon_id' => $addon->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_storefront_add_to_cart_rejects_unorderable_addon(): void
    {
        $product = $this->makeProduct();
        $other = $this->makeProduct(['name' => 'VPS Starter', 'price' => 500.00]);
        $addon = $this->makeAddon($other);
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'addons' => [['addon_id' => $addon->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('addons');

        $this->assertSame([], session('cart', []));
    }
}
