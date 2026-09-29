<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\AddOnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T2.3/T2.4 — admin surface for the live add-on lifecycle on an order:
 * attach (orders.addons.store) and cancel (orders.addons.destroy), both gated
 * behind orders.edit, plus the Add-ons tab on the order page.
 */
class AddOnAdminRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(array $permissionNames = ['orders.view', 'orders.edit']): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        // Permissions live on the ROLE (RBAC): sync() makes the role's set
        // exactly ours, so a "without orders.edit" user cannot inherit an
        // earlier grant in the same test.
        $permissionIds = [];

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => ucwords(str_replace('.', ' ', $name))]
            );
            $permissionIds[] = $permission->id;
        }

        $adminRole->permissions()->sync($permissionIds);
        $user->assignRole('admin');

        return $user;
    }

    public function test_both_addon_routes_are_forbidden_without_orders_edit(): void
    {
        $admin = $this->adminUser(['orders.view']);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product);

        $this->actingAs($admin)
            ->post(route('admin.orders.addons.store', $order), ['addon_id' => $addon->id, 'quantity' => 1])
            ->assertForbidden();

        $this->actingAs($admin)
            ->delete(route('admin.orders.addons.destroy', [$order, $parent]))
            ->assertForbidden();

        $this->assertSame(0, OrderItem::whereNotNull('product_addon_id')->count());
        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
    }

    public function test_store_attaches_the_addon_bills_immediately_and_audits(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product);

        $response = $this->actingAs($admin)->post(route('admin.orders.addons.store', $order), [
            'addon_id' => $addon->id,
            'quantity' => 2,
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('success');

        // Recurring line + setup-fee line, attached to the order's parent item.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_name' => 'Extra Storage',
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'unit_price' => 25.00,
            'total' => 50.00,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_name' => 'Extra Storage — Setup Fee',
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'unit_price' => 50.00,
        ]);

        // Billed now, no proration: 25 × 2 + 50 setup.
        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertSame(100.00, (float) $invoice->amount);

        $log = ActivityLog::where('action', 'addon.attached')->sole();
        $this->assertSame((int) $admin->id, (int) $log->user_id);
        $this->assertSame((int) $order->id, $log->metadata['order_id']);
        $this->assertSame((int) $addon->id, $log->metadata['product_addon_id']);
    }

    public function test_store_rejects_an_inactive_addon(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeParent($order, $product);
        $addon = $this->makeAddon($product, ['status' => 'inactive']);

        $response = $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.addons.store', $order), [
                'addon_id' => $addon->id,
                'quantity' => 1,
            ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHasErrors('addon_id');

        $this->assertSame(0, OrderItem::whereNotNull('product_addon_id')->count());
        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
        $this->assertSame(0, ActivityLog::where('action', 'addon.attached')->count());
    }

    public function test_store_rejects_an_addon_scoped_to_another_product(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $other = $this->makeProduct(['name' => 'VPS Starter', 'price' => 500.00]);
        $order = $this->makeOrder($customer, $product);
        $this->makeParent($order, $product);
        $addon = $this->makeAddon($other);

        $response = $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.addons.store', $order), [
                'addon_id' => $addon->id,
                'quantity' => 1,
            ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHasErrors('addon_id');

        $this->assertSame(0, OrderItem::whereNotNull('product_addon_id')->count());
        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
    }

    public function test_store_rejects_a_quantity_of_zero(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeParent($order, $product);
        $addon = $this->makeAddon($product);

        $response = $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.addons.store', $order), [
                'addon_id' => $addon->id,
                'quantity' => 0,
            ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHasErrors('quantity');

        $this->assertSame(0, OrderItem::whereNotNull('product_addon_id')->count());
        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
    }

    public function test_store_rejects_a_parent_item_from_another_order(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeParent($order, $product);
        $otherOrder = $this->makeOrder($customer, $product);
        $otherParent = $this->makeParent($otherOrder, $product);
        $addon = $this->makeAddon($product);

        $response = $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.addons.store', $order), [
                'addon_id' => $addon->id,
                'quantity' => 1,
                'parent_item_id' => $otherParent->id,
            ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHasErrors('parent_item_id');

        $this->assertSame(0, OrderItem::whereNotNull('product_addon_id')->count());
    }

    public function test_destroy_cancels_a_live_addon_row_and_audits_the_reason(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeParent($order, $product);
        $addon = $this->makeAddon($product);

        // Attach first, so the cancel acts on a real live row (the admin flow).
        $this->actingAs($admin)->post(route('admin.orders.addons.store', $order), [
            'addon_id' => $addon->id,
            'quantity' => 1,
        ]);

        $live = OrderItem::where('order_id', $order->id)->whereNotNull('next_billing_date')->sole();

        $response = $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->delete(route('admin.orders.addons.destroy', [$order, $live]), ['reason' => 'Customer downgraded']);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('success');

        $this->assertNull($live->fresh()->next_billing_date);

        $log = ActivityLog::where('action', 'addon.cancelled')->sole();
        $this->assertSame((int) $admin->id, (int) $log->user_id);
        $this->assertSame((int) $order->id, $log->metadata['order_id']);
        $this->assertSame((int) $live->id, $log->metadata['order_item_id']);
        $this->assertSame('Customer downgraded', $log->metadata['reason']);
    }

    public function test_destroy_is_404_for_a_non_addon_item(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $parent = $this->makeParent($order, $product);

        $this->actingAs($admin)
            ->delete(route('admin.orders.addons.destroy', [$order, $parent]))
            ->assertNotFound();

        $this->assertSame(0, ActivityLog::where('action', 'addon.cancelled')->count());
    }

    public function test_destroy_is_404_for_an_item_of_another_order(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeParent($order, $product);
        $otherOrder = $this->makeOrder($customer, $product);
        $otherParent = $this->makeParent($otherOrder, $product);
        $addon = $this->makeAddon($product);

        $this->actingAs($admin)->post(route('admin.orders.addons.store', $otherOrder), [
            'addon_id' => $addon->id,
            'quantity' => 1,
        ]);

        $foreignAddonRow = OrderItem::where('order_id', $otherOrder->id)->whereNotNull('next_billing_date')->sole();

        $this->actingAs($admin)
            ->delete(route('admin.orders.addons.destroy', [$order, $foreignAddonRow]))
            ->assertNotFound();

        $this->assertNotNull($foreignAddonRow->fresh()->next_billing_date);
    }

    public function test_order_page_lists_addons_and_shows_the_cancelled_badge(): void
    {
        $admin = $this->adminUser(['orders.view', 'orders.edit']);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product);

        $recurring = app(AddOnService::class)->attach($order, $parent, $addon);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order).'?tab=addons')
            ->assertOk()
            ->assertSee('Extra Storage')
            ->assertSee('Attach Add-on')
            ->assertDontSee('Cancelled');

        app(AddOnService::class)->cancel($recurring, 'Customer downgraded');

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->fresh()).'?tab=addons')
            ->assertOk()
            ->assertSee('Extra Storage')
            ->assertSee('Cancelled');
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Add-on Admin Test Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
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

    private function makeOrder(Customer $customer, Product $product, string $status = Order::STATUS_ACTIVE): Order
    {
        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => $status,
        ]);
    }

    private function makeParent(Order $order, Product $product): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'billing_cycles_count' => 1,
        ]);
    }
}
