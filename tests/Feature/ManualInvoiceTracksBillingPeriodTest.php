<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\AddOnService;
use App\Services\Billing\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A manually raised invoice bills a period — it must say which one, and it
 * must consume it.
 *
 * A "ghost invoice" — one raised by hand against a live recurring service —
 * used to carry no billing period at all: the duplicate-billing net could not
 * see it, and paying it moved no pointer, so the renewal engine could bill
 * the very same period again.
 */
class ManualInvoiceTracksBillingPeriodTest extends TestCase
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

    public function test_a_manual_invoice_bills_the_next_period_and_moves_only_the_generation_pointer(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $due = CarbonImmutable::today('Asia/Kolkata')->toDateString();
        [$order, $item] = $this->makeLiveOrder($customer, $product, $due);
        $account = $this->makeHostingAccount($customer, $product, $order, 'svc_period');

        $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$account->id],
            ])
            ->assertRedirect(route('admin.customers.show', $customer))
            ->assertSessionHasNoErrors();

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $line = $invoice->items()->sole();

        // The line says which period it bills.
        $this->assertSame($due, $line->billed_due_date->toDateString());
        $this->assertSame(1, (int) $line->billed_cycle_months);
        $this->assertSame($item->id, (int) $line->order_item_id);

        // The generation pointer moved one cycle; the pay-by date waits for
        // the money.
        $this->assertSame(
            CarbonImmutable::parse($due)->addMonth()->toDateString(),
            $item->fresh()->next_invoice_date->toDateString()
        );
        $this->assertSame($due, $item->fresh()->next_billing_date->toDateString());

        // The period is consumed, so the cycle counter follows the pointer.
        $this->assertSame(2, (int) $item->fresh()->billing_cycles_count);

        // The order summary follows the items.
        $this->assertSame(
            CarbonImmutable::parse($due)->addMonth()->toDateString(),
            $order->fresh()->next_invoice_date->toDateString()
        );
        $this->assertSame($due, $order->fresh()->next_billing_date->toDateString());
    }

    public function test_paying_the_manual_invoice_advances_the_pay_by_date(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $due = CarbonImmutable::today('Asia/Kolkata')->toDateString();
        [$order, $item] = $this->makeLiveOrder($customer, $product, $due);
        $account = $this->makeHostingAccount($customer, $product, $order, 'svc_paid');

        $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$account->id],
            ]);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        app(BillingService::class)->recordPayment((int) $invoice->id, (float) $invoice->total, 'bank_transfer');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // The money moves the pay-by date onto the end of the billed period.
        $this->assertSame(
            CarbonImmutable::parse($due)->addMonth()->toDateString(),
            $item->fresh()->next_billing_date->toDateString()
        );

        // The generation pointer stays where the raise put it.
        $this->assertSame(
            CarbonImmutable::parse($due)->addMonth()->toDateString(),
            $item->fresh()->next_invoice_date->toDateString()
        );

        // The order summary follows the items.
        $this->assertSame(
            CarbonImmutable::parse($due)->addMonth()->toDateString(),
            $order->fresh()->next_billing_date->toDateString()
        );
    }

    public function test_a_second_manual_invoice_on_the_same_order_raises_nothing(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $due = CarbonImmutable::today('Asia/Kolkata')->toDateString();
        [$order, $item] = $this->makeLiveOrder($customer, $product, $due);
        $account = $this->makeHostingAccount($customer, $product, $order, 'svc_twice');

        $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$account->id],
            ]);

        $second = $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$account->id],
            ]);

        $second->assertRedirect(route('admin.customers.show', $customer));
        $flash = (string) $second->getSession()->get('success');
        $this->assertStringContainsString('0 created, 1 existing', $flash);
        $this->assertStringContainsString('Unpaid', $flash);

        $this->assertSame(1, Invoice::count());

        // The period was consumed exactly once: the pointer advanced one cycle,
        // not two.
        $this->assertSame(
            CarbonImmutable::parse($due)->addMonth()->toDateString(),
            $item->fresh()->next_invoice_date->toDateString()
        );
        $this->assertSame(2, (int) $item->fresh()->billing_cycles_count);
    }

    public function test_the_renewal_engine_does_not_bill_a_period_a_manual_invoice_billed(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $due = $asOf->toDateString();

        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        [$order, $item] = $this->makeLiveOrder($customer, $product, $due);
        $account = $this->makeHostingAccount($customer, $product, $order, 'svc_engine');

        $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$account->id],
            ]);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $service = app(BillingService::class);

        // The period is consumed, so the engine has nothing left to bill.
        $result = $service->processRecurringBilling($asOf);

        $this->assertSame(0, $result['invoices_generated']);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        // Even with the generation pointer re-armed, the OPEN manual invoice
        // blocks its period: the net matches on the period the line now
        // carries, which is exactly what a period-less invoice could not do.
        $item->refresh()->update(['next_invoice_date' => $due]);
        $order->refresh()->update(['next_invoice_date' => $due]);
        $invoice->update(['status' => Invoice::STATUS_SENT]);

        $second = $service->processRecurringBilling($asOf);

        $this->assertSame(0, $second['invoices_generated']);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
    }

    public function test_the_addon_branch_bills_the_addons_next_period(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $due = CarbonImmutable::today('Asia/Kolkata')->addMonth()->toDateString();
        [$order] = $this->makeLiveOrder($customer, $product, $due);
        $parent = $order->items()->sole();
        $addon = $this->makeProductAddon($product, 'Extra Storage', 50.00);
        $addonItem = $this->makeAddonItem($order, $parent, $product, $addon, $due);

        $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'addon_ids' => [$addonItem->id],
            ])
            ->assertRedirect(route('admin.customers.show', $customer))
            ->assertSessionHasNoErrors();

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $line = $invoice->items()->sole();

        // The add-on line says which period it bills and links the order line.
        $this->assertSame($addonItem->id, (int) $line->order_item_id);
        $this->assertSame($due, $line->billed_due_date->toDateString());
        $this->assertSame(1, (int) $line->billed_cycle_months);

        // The generation pointer moved one cycle; the pay-by date waits for
        // the money.
        $this->assertSame(
            CarbonImmutable::parse($due)->addMonth()->toDateString(),
            $addonItem->fresh()->next_invoice_date->toDateString()
        );
        $this->assertSame($due, $addonItem->fresh()->next_billing_date->toDateString());
    }

    public function test_an_addon_whose_schedule_ended_cannot_be_selected(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $due = CarbonImmutable::today('Asia/Kolkata')->addMonth()->toDateString();
        [$order] = $this->makeLiveOrder($customer, $product, $due);
        $parent = $order->items()->sole();
        $addon = $this->makeProductAddon($product, 'Extra Storage', 50.00);

        // The schedule ended: the cycle limit cleared the generation pointer
        // while the pay-by date still stands.
        $addonItem = $this->makeAddonItem($order, $parent, $product, $addon, $due, scheduleEnded: true);

        $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'addon_ids' => [$addonItem->id],
            ])
            ->assertSessionHasErrors('error');

        $this->assertSame(0, Invoice::count());
        $this->assertNull($addonItem->fresh()->next_invoice_date);
    }

    public function test_the_addon_charge_bills_the_first_period_and_the_setup_fee_carries_none(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        [$order, $parent] = $this->makeLiveOrder($customer, $product, $asOf->addMonth()->toDateString());
        $addon = $this->makeProductAddon($product, 'Extra Storage', 25.00, 10.00);

        $recurring = app(AddOnService::class)->attach($order, $parent, $addon);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $lines = $invoice->items()->orderBy('id')->get();

        // The recurring line carries the add-on's first period — the one that
        // starts at the attach.
        $this->assertSame($asOf->toDateString(), $lines[0]->billed_due_date->toDateString());
        $this->assertSame(1, (int) $lines[0]->billed_cycle_months);

        // The setup fee is a one-time line: no period behind it.
        $this->assertNull($lines[1]->billed_due_date);
        $this->assertNull($lines[1]->billed_cycle_months);

        // The schedule was seeded at attach and nothing moved.
        $this->assertSame($asOf->addMonth()->toDateString(), $recurring->fresh()->next_invoice_date->toDateString());
        $this->assertSame($asOf->addMonth()->toDateString(), $recurring->fresh()->next_billing_date->toDateString());

        // Paying the charge moves neither pointer: the add-on's schedule
        // already stands at the end of the period it billed.
        app(BillingService::class)->recordPayment((int) $invoice->id, (float) $invoice->total, 'bank_transfer');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame($asOf->addMonth()->toDateString(), $recurring->fresh()->next_invoice_date->toDateString());
        $this->assertSame($asOf->addMonth()->toDateString(), $recurring->fresh()->next_billing_date->toDateString());
    }

    public function test_a_manual_invoice_against_an_order_without_a_schedule_moves_nothing(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makePendingOrder($customer, $product);
        $account = $this->makeHostingAccount($customer, $product, $order, 'svc_pending');

        $this->actingAs($admin)
            ->from(route('admin.customers.show', $customer))
            ->post(route('admin.customers.services.invoice-selected', $customer), [
                'service_ids' => [$account->id],
            ])
            ->assertRedirect(route('admin.customers.show', $customer))
            ->assertSessionHasNoErrors();

        $invoice = Invoice::where('order_id', $order->id)->sole();

        // Every item is billed, none of them with a period.
        $this->assertSame(2, $invoice->items()->count());

        foreach ($invoice->items as $line) {
            $this->assertNull($line->billed_due_date);
            $this->assertNull($line->billed_cycle_months);
        }

        // No pointer moved.
        foreach ($order->items as $item) {
            $this->assertNull($item->fresh()->next_invoice_date);
            $this->assertNull($item->fresh()->next_billing_date);
        }
    }

    public function test_an_order_time_invoice_bills_every_item_without_a_period(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makePendingOrder($customer, $product);

        // The order-creation path: the order is still pending and its items
        // carry no schedule, so the invoice bills them all without a period.
        $invoice = app(BillingService::class)->createInvoiceForOrder($order);

        $this->assertSame(2, $invoice->items()->count());

        foreach ($invoice->items as $line) {
            $this->assertNull($line->billed_due_date);
            $this->assertNull($line->billed_cycle_months);
        }

        foreach ($order->items as $item) {
            $this->assertNull($item->fresh()->next_invoice_date);
            $this->assertNull($item->fresh()->next_billing_date);
        }
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['customers.view', 'invoices.create', 'invoices.view'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    private function makeCustomer(string $company = 'Manual Period Corp'): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => $company,
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
     * An active order with a live monthly service: both pointers on the same
     * date, the way a live schedule looks.
     *
     * @return array{0: Order, 1: OrderItem}
     */
    private function makeLiveOrder(Customer $customer, Product $product, string $due): array
    {
        $order = Order::create([
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

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => (float) $product->price,
            'total' => (float) $product->price,
            'next_billing_date' => $due,
            'next_invoice_date' => $due,
            'billing_cycles_count' => 1,
        ]);

        return [$order, $item];
    }

    /**
     * A pending order whose items carry no schedule yet: the order-creation
     * shape, with a recurring line and a one-time line.
     */
    private function makePendingOrder(Customer $customer, Product $product): Order
    {
        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => Order::STATUS_PENDING,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => (float) $product->price,
            'total' => (float) $product->price,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name.' — Setup Fee',
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
        ]);

        return $order;
    }

    private function makeHostingAccount(Customer $customer, Product $product, Order $order, string $username): HostingAccount
    {
        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'username' => $username,
            'status' => 'active',
        ]);
    }

    private function makeProductAddon(Product $product, string $name, float $price, float $setupFee = 0.0): ProductAddon
    {
        return ProductAddon::create([
            'product_id' => $product->id,
            'name' => $name,
            'billing_cycle' => 'monthly',
            'price' => $price,
            'setup_fee' => $setupFee,
            'status' => 'active',
        ]);
    }

    /**
     * An add-on line on the order. A live schedule seeds both pointers to the
     * same date; an ended schedule keeps the pay-by date but has no
     * generation date left.
     */
    private function makeAddonItem(
        Order $order,
        OrderItem $parent,
        Product $product,
        ProductAddon $addon,
        string $due,
        bool $scheduleEnded = false,
    ): OrderItem {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $addon->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => (float) $addon->price,
            'total' => (float) $addon->price,
            'next_billing_date' => $due,
            'next_invoice_date' => $scheduleEnded ? null : $due,
            'parent_item_id' => $parent->id,
            'product_addon_id' => $addon->id,
        ]);
    }
}
