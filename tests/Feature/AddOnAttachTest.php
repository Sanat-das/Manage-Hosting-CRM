<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\User;
use App\Services\Billing\AddOnService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * T2.2 — post-signup add-on attach (WHMCS-style): billing starts immediately
 * at the add-on's own default cycle and price, with no proration, plus an
 * immediate `sent` invoice and an audit row.
 */
class AddOnAttachTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_to_active_order_bills_immediately_without_proration(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_ACTIVE);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product, ['price' => 25.00, 'setup_fee' => 50.00, 'billing_cycle' => 'monthly']);

        $recurring = app(AddOnService::class)->attach($order, $parent, $addon, 2);

        // Recurring row: frozen field values, the add-on's OWN cycle (not the
        // parent's), due today + cycle in Asia/Kolkata.
        $this->assertSame((int) $order->id, (int) $recurring->order_id);
        $this->assertSame((int) $product->id, (int) $recurring->product_id);
        $this->assertSame('Extra Storage', $recurring->product_name);
        $this->assertSame((int) $parent->id, (int) $recurring->parent_item_id);
        $this->assertSame((int) $addon->id, (int) $recurring->product_addon_id);
        $this->assertSame('monthly', $recurring->billing_cycle);
        $this->assertSame(2, (int) $recurring->quantity);
        $this->assertSame(25.00, (float) $recurring->unit_price);
        $this->assertSame(50.00, (float) $recurring->total);
        $this->assertSame(0, (int) $recurring->recurring_cycles_limit);
        $this->assertSame(1, (int) $recurring->billing_cycles_count);
        $this->assertSame(
            CarbonImmutable::today('Asia/Kolkata')->addMonth()->toDateString(),
            $recurring->next_billing_date->toDateString()
        );

        // The order summary follows the earliest item date — the add-on's.
        $this->assertSame(
            $recurring->next_billing_date->toDateString(),
            $order->fresh()->next_billing_date->toDateString()
        );

        // Setup fee: its own one_time row, billed once, never scheduled.
        $setup = OrderItem::where('product_name', 'Extra Storage — Setup Fee')->sole();
        $this->assertSame('one_time', $setup->billing_cycle);
        $this->assertSame(1, (int) $setup->quantity);
        $this->assertSame(50.00, (float) $setup->unit_price);
        $this->assertSame(50.00, (float) $setup->total);
        $this->assertSame(0, (int) $setup->billing_cycles_count);
        $this->assertNull($setup->next_billing_date);

        // One invoice, issued immediately: price × qty + setup, exactly two
        // lines (no proration line for the partial period).
        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertSame(100.00, (float) $invoice->amount);
        $this->assertSame(2, $invoice->items()->count());

        $descriptions = $invoice->items()->pluck('description')->all();
        $this->assertTrue(collect($descriptions)->contains(fn ($d) => str_contains((string) $d, 'Extra Storage — Monthly')));
        $this->assertTrue(collect($descriptions)->contains(fn ($d) => str_contains((string) $d, 'Setup Fee')));

        $setupLine = $invoice->items()->where('description', 'like', '%Setup Fee%')->sole();
        $this->assertSame(1, (int) $setupLine->quantity);
        $this->assertSame(50.00, (float) $setupLine->unit_price);
        $this->assertSame(50.00, (float) $setupLine->total);

        // Audit row carries the order, the new line and the definition.
        $this->assertDatabaseHas('activity_log', [
            'customer_id' => $customer->id,
            'user_id' => null,
            'action' => 'addon.attached',
        ]);

        $log = ActivityLog::where('action', 'addon.attached')->sole();
        $this->assertStringContainsString('Extra Storage', (string) $log->description);
        $this->assertStringContainsString($order->order_number, (string) $log->description);
        $this->assertSame((int) $order->id, (int) $log->metadata['order_id']);
        $this->assertSame((int) $recurring->id, (int) $log->metadata['order_item_id']);
        $this->assertSame((int) $addon->id, (int) $log->metadata['product_addon_id']);
    }

    public function test_attach_recomputes_the_order_total_and_keeps_the_attach_invoice_amount(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_ACTIVE);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product, ['price' => 25.00, 'setup_fee' => 50.00]);

        $this->assertSame(100.00, round((float) $order->fresh()->total, 2), 'Precondition: the order starts at the parent line total.');

        app(AddOnService::class)->attach($order, $parent, $addon, 2);

        // parent 100 + recurring 50 + setup 50. Without the total recompute the
        // order would keep its stale 100 and disagree with its own rows.
        $expected = 200.00;
        $this->assertSame($expected, round((float) $order->fresh()->total, 2));
        $this->assertSame($expected, round((float) OrderItem::where('order_id', $order->id)->sum('total'), 2));

        // The immediate invoice bills only the newly attached charges — not the
        // recomputed order total, which would charge the parent twice.
        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(100.00, round((float) $invoice->amount, 2));
    }

    public function test_attach_to_suspended_order_succeeds(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_SUSPENDED);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product, ['price' => 50.00, 'setup_fee' => 0]);

        $recurring = app(AddOnService::class)->attach($order, $parent, $addon);

        $this->assertSame((int) $addon->id, (int) $recurring->product_addon_id);
        $this->assertSame(50.00, (float) Invoice::where('order_id', $order->id)->sole()->amount);
    }

    public function test_attach_rejects_a_pending_order(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_PENDING);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product);

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->attach($order, $parent, $addon);
    }

    public function test_attach_rejects_a_terminated_order(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_TERMINATED);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product);

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->attach($order, $parent, $addon);
    }

    public function test_duplicate_live_attach_is_rejected_but_a_cancelled_row_does_not_block_reattach(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_ACTIVE);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product, ['price' => 50.00, 'setup_fee' => 0]);

        $service = app(AddOnService::class);
        $first = $service->attach($order, $parent, $addon);

        $this->assertSame(1, OrderItem::where('parent_item_id', $parent->id)->where('product_addon_id', $addon->id)->count());
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        try {
            $service->attach($order, $parent, $addon);
            $this->fail('A second live attach must be rejected.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(1, OrderItem::where('parent_item_id', $parent->id)->where('product_addon_id', $addon->id)->count());

        // A cancelled row (no schedule) no longer blocks a fresh attach.
        $service->cancel($first, 'Customer changed their mind');

        $second = $service->attach($order, $parent, $addon);

        $this->assertNotSame((int) $first->id, (int) $second->id);
        $this->assertNotNull($second->next_billing_date);
        $this->assertSame(2, OrderItem::where('parent_item_id', $parent->id)->where('product_addon_id', $addon->id)->count());
        $this->assertSame(2, Invoice::where('order_id', $order->id)->count());
    }

    public function test_one_time_addon_attach_has_no_next_billing_date(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_ACTIVE);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product, ['billing_cycle' => 'one_time', 'price' => 300.00, 'setup_fee' => 0]);

        $recurring = app(AddOnService::class)->attach($order, $parent, $addon, 0);

        // Quantity is floored at 1.
        $this->assertSame(1, (int) $recurring->quantity);
        // A zero-month cycle never schedules a renewal.
        $this->assertNull($recurring->next_billing_date);
        $this->assertSame(0, (int) $recurring->billing_cycles_count);
        $this->assertNull($order->fresh()->next_billing_date);
        $this->assertSame(300.00, (float) Invoice::where('order_id', $order->id)->sole()->amount);
    }

    public function test_attach_rejects_an_inactive_addon(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_ACTIVE);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product, ['status' => 'inactive']);

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->attach($order, $parent, $addon);
    }

    public function test_attach_rejects_an_addon_scoped_to_another_product(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $other = $this->makeProduct(['name' => 'VPS Starter', 'price' => 500.00]);
        $order = $this->makeOrder($customer, $product, Order::STATUS_ACTIVE);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($other);

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->attach($order, $parent, $addon);
    }

    public function test_attach_rejects_an_addon_line_as_the_parent(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_ACTIVE);
        $parent = $this->makeParent($order, $product);
        $first = $this->makeAddon($product, ['name' => 'Addon A', 'setup_fee' => 0]);
        $second = $this->makeAddon($product, ['name' => 'Addon B', 'setup_fee' => 0]);

        $recurring = app(AddOnService::class)->attach($order, $parent, $first);

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->attach($order, $recurring, $second);
    }

    public function test_attach_rejects_a_parent_from_another_order(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_ACTIVE);
        $otherOrder = $this->makeOrder($customer, $product, Order::STATUS_ACTIVE);
        $parent = $this->makeParent($order, $product);
        $addon = $this->makeAddon($product);

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->attach($otherOrder, $parent, $addon);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Attach Test Corp',
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
            'setup_fee' => 0,
            'status' => 'active',
        ], $attributes));
    }

    private function makeOrder(Customer $customer, Product $product, string $status, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => $status,
        ], $overrides));
    }

    private function makeParent(Order $order, Product $product, array $overrides = []): OrderItem
    {
        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'billing_cycles_count' => 1,
        ], $overrides));
    }
}
