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
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two billing pointers move independently: generation advances
 * next_invoice_date, payment advances next_billing_date.
 *
 * Before this split, a renewal invoice moved the pay-by date as soon as it was
 * generated, so a period the customer had not paid for counted as settled. The
 * date may only move when the money does — and never twice.
 */
class RenewalPaymentAdvancesDueDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_generation_advances_the_invoice_pointer_only(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $due = $asOf->toDateString();

        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        [$order, $item] = $this->makeLiveOrder($customer, $product, $due);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        $invoice = Invoice::where('order_id', $order->id)->sole();

        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertSame($due, $invoice->due_date->toDateString());
        $this->assertSame($due, $invoice->items->sole()->billed_due_date->toDateString());
        $this->assertSame($item->id, (int) $invoice->items->sole()->order_item_id);

        // The pay-by date waits for the money; only the generation date moves.
        $this->assertSame($due, $item->fresh()->next_billing_date->toDateString());
        $this->assertSame($asOf->addMonth()->toDateString(), $item->fresh()->next_invoice_date->toDateString());

        // The order summary mirrors both pointers.
        $this->assertSame($due, $order->fresh()->next_billing_date->toDateString());
        $this->assertSame($asOf->addMonth()->toDateString(), $order->fresh()->next_invoice_date->toDateString());
    }

    public function test_paying_the_invoice_advances_the_pay_by_date(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $due = $asOf->toDateString();

        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        [$order, $item] = $this->makeLiveOrder($customer, $product, $due);

        $service = app(BillingService::class);
        $service->processRecurringBilling($asOf);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $service->recordPayment((int) $invoice->id, (float) $invoice->total, 'bank_transfer');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame($asOf->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame($asOf->addMonth()->toDateString(), $item->fresh()->next_invoice_date->toDateString());

        // The summary follows the item on both pointers.
        $this->assertSame($asOf->addMonth()->toDateString(), $order->fresh()->next_billing_date->toDateString());
        $this->assertSame($asOf->addMonth()->toDateString(), $order->fresh()->next_invoice_date->toDateString());
    }

    public function test_a_partial_payment_moves_nothing(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $due = $asOf->toDateString();

        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        [$order, $item] = $this->makeLiveOrder($customer, $product, $due);

        $service = app(BillingService::class);
        $service->processRecurringBilling($asOf);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $service->recordPayment((int) $invoice->id, round((float) $invoice->total / 2, 2), 'bank_transfer');

        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->fresh()->status);
        $this->assertSame($due, $item->fresh()->next_billing_date->toDateString());
        $this->assertSame($asOf->addMonth()->toDateString(), $item->fresh()->next_invoice_date->toDateString());
    }

    public function test_an_open_invoice_is_not_billed_again_inside_a_wide_window(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $due = $asOf->toDateString();

        $this->setWindow(90);

        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        [$order, $item] = $this->makeLiveOrder($customer, $product, $due);

        $service = app(BillingService::class);
        $first = $service->processRecurringBilling($asOf);
        $second = $service->processRecurringBilling($asOf);

        $this->assertSame(1, $first['invoices_generated']);
        $this->assertSame(0, $second['invoices_generated'], 'The unpaid renewal must not be billed twice.');
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        // Force the same period due again while the invoice is still open:
        // the duplicate-billing guard — not the once-per-cycle guard — is what
        // must stop it. Refresh first: the instance still holds the pre-billing
        // attributes, so the new date would read as unchanged.
        $item->refresh()->update(['next_invoice_date' => $due, 'last_billing_date' => null]);

        $third = $service->processRecurringBilling($asOf);

        $this->assertSame(0, $third['invoices_generated']);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        $this->assertSame($due, $item->fresh()->next_invoice_date->toDateString());
    }

    public function test_voiding_the_unpaid_renewal_bills_the_same_period_again(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $due = $asOf->toDateString();

        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        [$order, $item] = $this->makeLiveOrder($customer, $product, $due);

        $service = app(BillingService::class);
        $service->processRecurringBilling($asOf);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->voidInvoice($invoice);

        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
        $this->assertSame($due, $item->fresh()->next_invoice_date->toDateString(), 'The void re-arms the billed period.');
        $this->assertSame($due, $item->fresh()->next_billing_date->toDateString());

        $result = $service->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(2, Invoice::where('order_id', $order->id)->count());

        $rebilled = Invoice::where('order_id', $order->id)
            ->where('status', Invoice::STATUS_SENT)
            ->sole();

        $this->assertSame($due, $rebilled->due_date->toDateString());
        $this->assertNotSame((int) $invoice->id, (int) $rebilled->id);
    }

    public function test_the_cycle_limit_ends_generation_and_the_last_payment_clears_the_pay_by_date(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $due = $asOf->toDateString();

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['recurring_cycles_limit' => 1]);

        $order = $this->makeOrder($customer, $product, $due);
        $item = $this->makeItem($order, $product, [
            'next_billing_date' => $due,
            'next_invoice_date' => $due,
            'recurring_cycles_limit' => 1,
            'billing_cycles_count' => 0,
        ]);

        $service = app(BillingService::class);
        $service->processRecurringBilling($asOf);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);

        // The next run sees the next period due and the cycle limit exhausted:
        // generation ends, the pay-by date stays where the customer owes it.
        $service->processRecurringBilling($asOf->addMonth());

        $this->assertNull($item->fresh()->next_invoice_date);
        $this->assertSame($due, $item->fresh()->next_billing_date->toDateString());

        $service->recordPayment((int) $invoice->id, (float) $invoice->total, 'bank_transfer');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertNull($item->fresh()->next_billing_date, 'A settled final invoice clears the pay-by date.');
        $this->assertNull($order->fresh()->next_billing_date);
    }

    private function setWindow(int $days): void
    {
        $settings = app(BillingSettings::class);
        $settings->renewal_invoice_days = $days;
        $settings->save();
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
            'company' => 'Renewal Payment Corp',
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
     * @return array{0: Order, 1: OrderItem}
     */
    private function makeLiveOrder(Customer $customer, Product $product, string $due): array
    {
        $order = $this->makeOrder($customer, $product, $due);

        $item = $this->makeItem($order, $product, [
            'next_billing_date' => $due,
            'next_invoice_date' => $due,
            'billing_cycles_count' => 1,
        ]);

        return [$order, $item];
    }

    private function makeOrder(Customer $customer, Product $product, string $due): Order
    {
        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => $due,
            'next_invoice_date' => $due,
        ]);
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
