<?php

namespace Tests\Feature;

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
use Tests\TestCase;

/**
 * T2.2 — a setup fee on a post-signup attach is billed once on the immediate
 * invoice and never re-billed: its row has no schedule and no cycle counter
 * even after the add-on renews on its own cycle.
 */
class AddOnSetupFeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_bills_the_setup_fee_once_and_never_renews_it(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, [
            'next_billing_date' => $asOf->addMonth()->toDateString(),
        ]);
        $parent = $this->makeParent($order, $product, [
            'next_billing_date' => $asOf->addMonth()->toDateString(),
        ]);
        $addon = $this->makeAddon($product, ['price' => 500.00, 'setup_fee' => 250.00]);

        $recurring = app(AddOnService::class)->attach($order, $parent, $addon, 2);

        $setup = OrderItem::where('parent_item_id', $parent->id)
            ->where('billing_cycle', 'one_time')
            ->sole();

        // The immediate invoice carries both the recurring line and the setup line.
        $attachInvoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(Invoice::STATUS_SENT, $attachInvoice->status);
        $this->assertSame(1250.00, (float) $attachInvoice->amount);
        $this->assertSame(2, $attachInvoice->items()->count());
        $this->assertSame(
            250.00,
            (float) $attachInvoice->items()->where('description', 'like', '%Setup Fee%')->sole()->total
        );

        // Two renewal passes, each a day past the add-on's due date. (The engine
        // parses item date strings in the app timezone, where a line due
        // "today" does not compare <= an Asia/Kolkata start-of-day; a day later
        // it bills reliably.)
        $dueOne = $asOf->addMonth()->addDay();
        $dueTwo = $dueOne->addMonths(1)->addDay();
        $service = app(BillingService::class);
        $first = $service->processRecurringBilling($dueOne);
        $second = $service->processRecurringBilling($dueTwo);

        $this->assertSame(1, $first['invoices_generated']);
        $this->assertSame(0, $first['errors']);
        $this->assertSame(1, $second['invoices_generated']);
        $this->assertSame(0, $second['errors']);

        // Neither renewal bills the setup fee — both bill parent + add-on only.
        $renewals = Invoice::where('order_id', $order->id)
            ->where('notes', 'Auto-generated renewal invoice')
            ->get();
        $this->assertCount(2, $renewals);

        foreach ($renewals as $renewal) {
            $this->assertSame(1100.00, (float) $renewal->amount);
            $this->assertSame(2, $renewal->items()->count());
            $this->assertFalse(
                $renewal->items->contains(fn ($line) => str_contains((string) $line->description, 'Setup Fee'))
            );
        }

        // The setup row was never scheduled nor counted.
        $this->assertNull($setup->fresh()->next_billing_date);
        $this->assertSame(0, (int) $setup->fresh()->billing_cycles_count);

        // The recurring add-on row renewed twice, each advance from its OWN
        // due date (attach seeded today + cycle, then two anniversary steps).
        $this->assertSame(3, (int) $recurring->fresh()->billing_cycles_count);
        $this->assertSame($asOf->addMonth()->addMonths(2)->toDateString(), $recurring->fresh()->next_billing_date->toDateString());
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Setup Fee Test Corp',
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
            'billing_cycles_count' => 1,
        ], $overrides));
    }
}
