<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A void or a cancelled invoice hands its billing period back.
 *
 * The renewal engine issues an invoice and advances the service's next
 * billing date in the same transaction. When that invoice is voided the
 * period must go back, or the service silently stops billing for it until
 * the next cycle — a year of free service for an unpaid invoice.
 */
class InvoiceVoidRewindsScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie settings are container-scoped; resolve a fresh instance.
        app()->forgetScopedInstances();

        // Without an active 'invoice_created' template the renewal run counts
        // the invoice email as a failure.
        EmailTemplate::firstOrCreate(
            ['name' => 'invoice_created'],
            [
                'subject' => 'Invoice {{invoice_no}}',
                'body' => 'Invoice {{invoice_no}} is ready.',
                'status' => 'active',
            ],
        );
    }

    public function test_voiding_a_renewal_invoice_gives_the_period_back(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');

        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $due = $asOf;

        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due->toDateString()]);
        $item = $this->makeItem($order, $product, [
            'next_billing_date' => $due->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);

        $invoice = Invoice::where('order_id', $order->id)->sole();

        // The renewal advanced the schedule and recorded the period it billed.
        $this->assertSame($due->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $item->fresh()->billing_cycles_count);
        $this->assertNotNull($item->fresh()->last_billing_date);
        $this->assertSame($due->toDateString(), $invoice->items->first()->billed_due_date->toDateString());

        $this->voidInvoice($invoice);

        $invoice->refresh();

        $this->assertSame(Invoice::STATUS_VOID, $invoice->status);

        $fresh = $item->fresh();

        $this->assertSame($due->toDateString(), $fresh->next_billing_date->toDateString());
        $this->assertNull($fresh->last_billing_date);
        $this->assertSame(1, $fresh->billing_cycles_count);

        // The order summary follows the items again.
        $this->assertSame($due->toDateString(), $order->fresh()->next_billing_date->toDateString());
    }

    public function test_voiding_an_invoice_that_billed_nothing_leaves_the_schedule_alone(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');

        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $due = $asOf;

        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due->toDateString()]);
        $item = $this->makeItem($order, $product, [
            'next_billing_date' => $due->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        // A hand-written invoice: it billed no period, so there is nothing to
        // give back.
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'invoice_no' => 'INV-2026-'.random_int(10000, 99999),
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => $due->toDateString(),
        ]);

        $invoice->items()->create([
            'description' => 'Manual line',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
        ]);

        $this->voidInvoice($invoice);

        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
        $this->assertSame($due->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame(1, $item->fresh()->billing_cycles_count);
    }

    public function test_voiding_does_not_rewind_a_period_that_has_since_been_billed_again(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');

        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $due = $asOf;

        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due->toDateString()]);
        $item = $this->makeItem($order, $product, [
            'next_billing_date' => $due->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        app(BillingService::class)->processRecurringBilling($asOf);

        $invoice = Invoice::where('order_id', $order->id)->sole();

        // The service has since been billed again: the schedule sits one
        // cycle further on, so the void must not touch it.
        $later = $due->addMonths(2)->toDateString();
        $item->update(['next_billing_date' => $later, 'billing_cycles_count' => 3]);

        $this->voidInvoice($invoice);

        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
        $this->assertSame($later, $item->fresh()->next_billing_date->toDateString());
        $this->assertSame(3, $item->fresh()->billing_cycles_count);
    }

    private function voidInvoice(Invoice $invoice): void
    {
        $this->actingAsEditor()->put(route('admin.invoices.update', $invoice), [
            'status' => Invoice::STATUS_VOID,
            'due_date' => $invoice->due_date->toDateString(),
            'items' => $invoice->items->map(fn ($line) => [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price,
            ])->all(),
        ])->assertRedirect();
    }

    private function actingAsEditor()
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'invoices.view'], ['label' => 'View Invoices']);
        $edit = Permission::firstOrCreate(['name' => 'invoices.edit'], ['label' => 'Edit Invoices']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $edit->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Void Rewind Corp',
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

    private function makeItem(Order $order, Product $product, array $overrides = []): OrderItem
    {
        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => (float) $product->price,
            'total' => (float) $product->price,
        ], $overrides));
    }
}
