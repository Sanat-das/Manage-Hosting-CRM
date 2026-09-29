<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\User;
use App\Services\Billing\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddOnRecurringBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_fee_line_is_never_renewed_while_the_parent_and_addon_renew(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['name' => 'Shared Hosting', 'price' => 100.00]);
        $addon = ProductAddon::create([
            'product_id' => $product->id,
            'name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
            'price' => 500.00,
            'setup_fee' => 250.00,
            'status' => 'active',
        ]);

        $order = $this->makeActiveOrder($customer, $product, [
            'total' => 850.00,
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
            'unit_price' => 500.00,
            'total' => 500.00,
            'recurring_cycles_limit' => 0,
            'next_billing_date' => $asOf->subDay()->toDateString(),
            'billing_cycles_count' => 1,
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
        ]);

        $setup = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Extra Storage — Setup Fee',
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'unit_price' => 250.00,
            'total' => 250.00,
            'recurring_cycles_limit' => 0,
            'next_billing_date' => null,
            'billing_cycles_count' => 0,
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(600.00, (float) $invoice->amount);
        $this->assertSame(2, $invoice->items()->count());

        $descriptions = $invoice->items()->pluck('description')->all();
        $this->assertTrue(collect($descriptions)->contains(fn ($d) => str_contains($d, 'Shared Hosting')));
        $this->assertTrue(collect($descriptions)->contains(fn ($d) => str_contains($d, 'Extra Storage')));
        $this->assertFalse(collect($descriptions)->contains(fn ($d) => str_contains($d, 'Setup Fee')));

        $this->assertNull($setup->fresh()->next_billing_date);
        $this->assertSame(0, $setup->fresh()->billing_cycles_count);

        // Renewal advances each item from ITS OWN due date (the engine bills
        // on the item's anniversary, not the run date).
        $expectedNext = $asOf->subDay()->addMonth()->toDateString();
        foreach ([$parent->id, $order->items()->where('product_name', 'Extra Storage')->value('id')] as $itemId) {
            $item = OrderItem::find($itemId);
            $this->assertSame($expectedNext, $item->fresh()->next_billing_date->toDateString());
            $this->assertSame(2, $item->fresh()->billing_cycles_count);
        }
    }

    public function test_addon_is_billed_on_its_own_cycle_not_with_the_parent(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['name' => 'Shared Hosting', 'price' => 100.00]);
        $addon = ProductAddon::create([
            'product_id' => $product->id,
            'name' => 'Annual Backup',
            'billing_cycle' => 'annual',
            'price' => 1200.00,
            'setup_fee' => 0,
            'status' => 'active',
        ]);

        $order = $this->makeActiveOrder($customer, $product, [
            'total' => 1300.00,
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

        $addonItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Annual Backup',
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'unit_price' => 1200.00,
            'total' => 1200.00,
            'recurring_cycles_limit' => 0,
            'next_billing_date' => $asOf->addMonths(6)->toDateString(),
            'billing_cycles_count' => 1,
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
        ]);

        $service = app(BillingService::class);

        $first = $service->processRecurringBilling($asOf);

        $this->assertSame(1, $first['invoices_generated']);
        $this->assertSame(0, $first['errors']);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(100.00, (float) $invoice->amount);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertStringContainsString('Shared Hosting', $invoice->items()->first()->description);

        $this->assertSame($asOf->addMonths(6)->toDateString(), $addonItem->fresh()->next_billing_date->toDateString());
        $this->assertSame(1, $addonItem->fresh()->billing_cycles_count);
        $this->assertSame($asOf->subDay()->addMonth()->toDateString(), $order->fresh()->next_billing_date->toDateString());

        $secondAsOf = $asOf->addMonth()->addDay();
        $second = $service->processRecurringBilling($secondAsOf);

        $this->assertSame(1, $second['invoices_generated']);
        $this->assertSame(0, $second['errors']);
        $this->assertSame(2, Invoice::where('order_id', $order->id)->count());

        foreach (Invoice::where('order_id', $order->id)->get() as $renewal) {
            $this->assertSame(1, $renewal->items()->count());
            $this->assertStringContainsString('Shared Hosting', $renewal->items()->first()->description);
        }

        $this->assertSame($asOf->addMonths(6)->toDateString(), $addonItem->fresh()->next_billing_date->toDateString());
        $this->assertSame(1, $addonItem->fresh()->billing_cycles_count);
        $this->assertSame($asOf->subDay()->addMonths(2)->toDateString(), $order->fresh()->next_billing_date->toDateString());
    }

    public function test_addon_due_date_renewal_bills_only_the_addon(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['name' => 'Shared Hosting', 'price' => 100.00]);
        $addon = ProductAddon::create([
            'product_id' => $product->id,
            'name' => 'Annual Backup',
            'billing_cycle' => 'annual',
            'price' => 600.00,
            'setup_fee' => 0,
            'status' => 'active',
        ]);

        $order = $this->makeActiveOrder($customer, $product, [
            'total' => 1300.00,
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
            'next_billing_date' => $asOf->addMonth()->toDateString(),
            'billing_cycles_count' => 2,
        ]);

        $addonItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Annual Backup',
            'billing_cycle' => 'annual',
            'quantity' => 2,
            'unit_price' => 600.00,
            'total' => 1200.00,
            'recurring_cycles_limit' => 0,
            'next_billing_date' => $asOf->subDay()->toDateString(),
            'billing_cycles_count' => 1,
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(1200.00, (float) $invoice->amount);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertStringContainsString('Annual Backup', $invoice->items()->first()->description);

        $this->assertSame($asOf->subDay()->addMonths(12)->toDateString(), $addonItem->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $addonItem->fresh()->billing_cycles_count);
        $this->assertSame($asOf->addMonth()->toDateString(), $parent->fresh()->next_billing_date->toDateString());
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Addon Test Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting',
            'price' => 499.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ], $attributes));
    }

    private function makeActiveOrder(Customer $customer, Product $product, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => CarbonImmutable::today('Asia/Kolkata')->subDay()->toDateString(),
        ], $overrides));
    }
}
