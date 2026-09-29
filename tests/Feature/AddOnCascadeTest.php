<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\User;
use App\Services\Billing\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T2.6 — a parent service reaching its product-defined fixed term must end the
 * attached add-on's billing schedule too, and terminate the order once nothing
 * remains recurring. Order suspension already stops every item (regression).
 */
class AddOnCascadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_auto_termination_ends_the_attached_addon_schedule(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $customer = $this->makeCustomer();
        $product = $this->makeProduct([
            'name' => 'Shared Hosting',
            'price' => 100.00,
            'auto_terminate_value' => 30,
            'auto_terminate_unit' => 'days',
        ]);
        $addon = ProductAddon::create([
            'product_id' => $product->id,
            'name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
            'price' => 50.00,
            'status' => 'active',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 150.00,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => $asOf->addMonth()->toDateString(),
        ]);

        $parent = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Shared Hosting',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'next_billing_date' => $asOf->addMonth()->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        // As AddOnService::materialize() creates it: the add-on row inherits
        // the parent's product id, and therefore the parent's fixed term.
        $addonItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 50.00,
            'total' => 50.00,
            'recurring_cycles_limit' => 0,
            'next_billing_date' => $asOf->addMonth()->toDateString(),
            'billing_cycles_count' => 1,
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
        ]);

        $this->markActivated($order, $asOf->subDays(31)); // 30-day term elapsed

        $result = app(BillingService::class)->processAutoTerminations($asOf);

        $this->assertSame(1, $result['terminated']);
        $this->assertSame(0, $result['errors']);
        $this->assertNull($parent->fresh()->next_billing_date, 'The parent schedule must end at its fixed term.');
        $this->assertNull($addonItem->fresh()->next_billing_date, 'The add-on must stop renewing with its parent.');
        $this->assertSame(Order::STATUS_TERMINATED, $order->fresh()->status);
    }

    public function test_suspended_order_bills_neither_the_parent_nor_the_addon(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['name' => 'Shared Hosting', 'price' => 100.00]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 150.00,
            'status' => Order::STATUS_SUSPENDED,
            'next_billing_date' => $asOf->subDay()->toDateString(),
        ]);

        $parent = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Shared Hosting',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'next_billing_date' => $asOf->subDay()->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 50.00,
            'total' => 50.00,
            'next_billing_date' => $asOf->subDay()->toDateString(),
            'billing_cycles_count' => 1,
            'parent_item_id' => $parent->id,
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(0, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame(0, Invoice::count());
        $this->assertSame($asOf->subDay()->toDateString(), $parent->fresh()->next_billing_date->toDateString());
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Cascade Test Corp',
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

    /**
     * Record the order's activation audit row (what auto-termination measures
     * the fixed term from), backdated to the given date.
     */
    private function markActivated(Order $order, \DateTimeInterface $when): void
    {
        $history = OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => Order::STATUS_PENDING,
            'to_status' => Order::STATUS_ACTIVE,
            'changed_by_user_id' => null,
        ]);

        OrderStatusHistory::query()->whereKey($history->id)->update([
            'created_at' => $when->format('Y-m-d H:i:s'),
        ]);
    }
}
