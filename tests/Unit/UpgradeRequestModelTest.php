<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\UpgradeRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpgradeRequestModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $name = 'Test Product'): Product
    {
        return Product::create([
            'name' => $name,
            'price' => 0,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create(['user_id' => User::factory()->create()->id]);
    }

    private function makeOrder(Customer $customer, Product $product): Order
    {
        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'total' => 0,
            'status' => Order::STATUS_PENDING,
        ]);
    }

    public function test_status_constants_match_enum(): void
    {
        $this->assertSame('pending', UpgradeRequest::STATUS_PENDING);
        $this->assertSame('applied', UpgradeRequest::STATUS_APPLIED);
        $this->assertSame('cancelled', UpgradeRequest::STATUS_CANCELLED);
        $this->assertSame(
            ['pending', 'applied', 'cancelled'],
            UpgradeRequest::STATUSES
        );
    }

    public function test_pending_scope_filters_by_status(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $to = $this->makeProduct('Bigger Plan');

        UpgradeRequest::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'from_product_id' => $product->id,
            'to_product_id' => $to->id,
            'billing_cycle' => 'monthly',
        ]);
        UpgradeRequest::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'from_product_id' => $product->id,
            'to_product_id' => $to->id,
            'billing_cycle' => 'monthly',
            'status' => UpgradeRequest::STATUS_APPLIED,
        ]);

        $this->assertCount(1, UpgradeRequest::pending()->get());
        $this->assertSame(UpgradeRequest::STATUS_PENDING, UpgradeRequest::pending()->first()->status);
    }

    public function test_decimal_and_date_casts(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $to = $this->makeProduct('Bigger Plan');

        $upgrade = UpgradeRequest::create([
            'upgrade_no' => 'UPG-2026-0001',
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'from_product_id' => $product->id,
            'to_product_id' => $to->id,
            'billing_cycle' => 'monthly',
            'credited' => 12.5,
            'debited' => 3.25,
            'setup_fee' => 10,
            'payable' => 0.75,
            'credit_amount' => 1,
            'approved_at' => '2026-09-30 10:00:00',
            'applied_at' => '2026-09-30 11:30:00',
        ]);

        $this->assertSame('12.50', $upgrade->credited);
        $this->assertSame('3.25', $upgrade->debited);
        $this->assertSame('10.00', $upgrade->setup_fee);
        $this->assertSame('0.75', $upgrade->payable);
        $this->assertSame('1.00', $upgrade->credit_amount);
        $this->assertInstanceOf(Carbon::class, $upgrade->approved_at);
        $this->assertInstanceOf(Carbon::class, $upgrade->applied_at);
        $this->assertNull($upgrade->cancelled_at);
    }

    public function test_relations_resolve(): void
    {
        $customer = $this->makeCustomer();
        $from = $this->makeProduct('Small Plan');
        $order = $this->makeOrder($customer, $from);
        $to = $this->makeProduct('Bigger Plan');

        $upgrade = UpgradeRequest::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'billing_cycle' => 'monthly',
        ]);

        $this->assertSame($order->id, $upgrade->order->id);
        $this->assertSame($customer->id, $upgrade->customer->id);
        $this->assertNull($upgrade->invoice);
        $this->assertSame($from->id, $upgrade->fromProduct->id);
        $this->assertSame($to->id, $upgrade->toProduct->id);
        $this->assertSame($upgrade->id, $upgrade->order->upgradeRequests->first()->id);
    }

    public function test_upgrade_no_attribute_falls_back_to_row_id(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $to = $this->makeProduct('Bigger Plan');

        $unnumbered = UpgradeRequest::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'from_product_id' => $product->id,
            'to_product_id' => $to->id,
            'billing_cycle' => 'monthly',
        ]);

        $this->assertSame('#'.$unnumbered->id, $unnumbered->upgrade_no);

        $numbered = UpgradeRequest::create([
            'upgrade_no' => 'UPG-2026-0042',
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'from_product_id' => $product->id,
            'to_product_id' => $to->id,
            'billing_cycle' => 'monthly',
        ]);

        $this->assertSame('UPG-2026-0042', $numbered->upgrade_no);
    }
}
