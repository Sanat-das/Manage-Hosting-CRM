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
use App\Services\Billing\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * T2.2 — post-signup add-on cancel (WHMCS-style): the schedule stops at the
 * period end (no refund, no void), the order summary is recomputed and the
 * event is audit-logged.
 */
class AddOnCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_clears_next_billing_date_resyncs_the_order_and_logs_the_actor(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $parent = $this->makeParent($order, $product, ['next_billing_date' => null]);
        $addon = $this->makeAddon($product, ['price' => 50.00, 'setup_fee' => 0]);
        $actor = User::factory()->create();

        $service = app(AddOnService::class);
        $recurring = $service->attach($order, $parent, $addon);

        // Attaching put the add-on's date on the order summary; the parent has
        // no schedule of its own.
        $this->assertNotNull($order->fresh()->next_billing_date);

        $service->cancel($recurring, 'Customer downgraded', $actor);

        $this->assertNull($recurring->fresh()->next_billing_date);
        $this->assertNull($order->fresh()->next_billing_date);

        $log = ActivityLog::where('action', 'addon.cancelled')->sole();
        $this->assertSame((int) $customer->id, (int) $log->customer_id);
        $this->assertSame((int) $actor->id, (int) $log->user_id);
        $this->assertStringContainsString('Extra Storage', (string) $log->description);
        $this->assertStringContainsString($order->order_number, (string) $log->description);
        $this->assertStringContainsString('Customer downgraded', (string) $log->description);
        $this->assertSame((int) $order->id, (int) $log->metadata['order_id']);
        $this->assertSame((int) $recurring->id, (int) $log->metadata['order_item_id']);
        $this->assertSame((int) $addon->id, (int) $log->metadata['product_addon_id']);
        $this->assertSame('Customer downgraded', $log->metadata['reason']);
    }

    public function test_a_cancelled_addon_is_never_invoiced_by_a_later_recurring_run(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $asOf->addMonth()->toDateString()]);
        $parent = $this->makeParent($order, $product, [
            'next_billing_date' => $asOf->addMonth()->toDateString(),
        ]);
        $addon = $this->makeAddon($product, ['price' => 50.00, 'setup_fee' => 0]);

        $service = app(AddOnService::class);
        $recurring = $service->attach($order, $parent, $addon);

        $this->assertSame(1, Invoice::where('order_id', $order->id)->count(), 'Attach must bill immediately.');

        // The add-on's own renewal date arrives: it alone is due today.
        $recurring->update(['next_billing_date' => $asOf->toDateString()]);
        $order->update(['next_billing_date' => $asOf->toDateString()]);

        $service->cancel($recurring);

        $this->assertNull($recurring->fresh()->next_billing_date);
        // Cancel recomputed the order summary back to the parent's date — the
        // only schedule left.
        $this->assertSame($asOf->addMonth()->toDateString(), $order->fresh()->next_billing_date->toDateString());

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(0, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count(), 'The cancelled line must not be re-invoiced.');
    }

    public function test_cancelling_a_non_add_on_line_throws(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $parent = $this->makeParent($order, $product);

        $this->expectException(InvalidArgumentException::class);

        app(AddOnService::class)->cancel($parent);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Cancel Test Corp',
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

    private function makeOrder(Customer $customer, Product $product, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => Order::STATUS_ACTIVE,
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
            'next_billing_date' => null,
            'billing_cycles_count' => 1,
        ], $overrides));
    }
}
