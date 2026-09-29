<?php

namespace Tests\Feature;

use App\Models\Customer;
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
 * F3 — renewal invoice generation window: invoices are generated
 * `renewal_invoice_days` before an item's due date, due ON the item's own
 * date, and the cycle advances from that date (no drift). 0 keeps the
 * historical "bill on the due date" cadence.
 */
class RenewalInvoiceWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie settings are container-scoped; resolve a fresh instance.
        app()->forgetScopedInstances();
    }

    public function test_window_generates_early_due_on_the_items_own_date_and_advances_from_it(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $this->setWindow(5);

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 100.00]);

        // Due in 4 days — inside the 5-day generation window.
        $due = $asOf->addDays(4);
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due->toDateString()]);
        $item = $this->makeItem($order, $product, [
            'next_billing_date' => $due->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertSame(100.00, (float) $invoice->amount);
        $this->assertSame(
            $due->toDateString(),
            $invoice->due_date->toDateString(),
            'The invoice is due on the item\'s own date, not today + 7.'
        );

        // The cycle advanced from the item's OWN due date, not the run date.
        $this->assertSame($due->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $item->fresh()->billing_cycles_count);
        $this->assertSame($due->addMonth()->toDateString(), $order->fresh()->next_billing_date->toDateString());
    }

    public function test_an_item_outside_the_window_is_not_billed(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $this->setWindow(5);

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 100.00]);

        // Due in 6 days — one day past the window.
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $asOf->addDays(6)->toDateString()]);
        $this->makeItem($order, $product, [
            'next_billing_date' => $asOf->addDays(6)->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(0, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_repeated_runs_inside_the_window_do_not_duplicate_the_invoice(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $this->setWindow(5);

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 100.00]);

        $due = $asOf->addDays(4);
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due->toDateString()]);
        $item = $this->makeItem($order, $product, [
            'next_billing_date' => $due->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $service = app(BillingService::class);

        $first = $service->processRecurringBilling($asOf);
        $second = $service->processRecurringBilling($asOf);            // same day
        $third = $service->processRecurringBilling($asOf->addDay());   // next day, still in the window

        $this->assertSame(1, $first['invoices_generated']);
        $this->assertSame(0, $second['invoices_generated']);
        $this->assertSame(0, $third['invoices_generated']);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        // The advanced date is still the next anniversary.
        $this->assertSame($due->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $item->fresh()->billing_cycles_count);
    }

    public function test_window_zero_keeps_the_historical_cadence(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');

        // The class default is 0 — no window configured.
        $this->assertSame(0, app(BillingSettings::class)->renewal_invoice_days);

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 100.00]);

        // Not due yet — must not be billed with a zero window.
        $notDue = $this->makeOrder($customer, $product, ['next_billing_date' => $asOf->addDays(3)->toDateString()]);
        $notDueItem = $this->makeItem($notDue, $product, [
            'next_billing_date' => $asOf->addDays(3)->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        // Due — billed on the run, due on its own date.
        $dueOrder = $this->makeOrder($customer, $product, ['next_billing_date' => $asOf->subDay()->toDateString()]);
        $dueItem = $this->makeItem($dueOrder, $product, [
            'next_billing_date' => $asOf->subDay()->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, Invoice::where('order_id', $notDue->id)->count());

        $invoice = Invoice::where('order_id', $dueOrder->id)->sole();
        $this->assertSame($asOf->subDay()->toDateString(), $invoice->due_date->toDateString());
        $this->assertSame($asOf->subDay()->addMonth()->toDateString(), $dueItem->fresh()->next_billing_date->toDateString());

        // The not-yet-due item kept its schedule untouched.
        $this->assertSame($asOf->addDays(3)->toDateString(), $notDueItem->fresh()->next_billing_date->toDateString());
    }

    /**
     * A window wider than the item's cycle must not bill successive future
     * periods: the once-per-cycle guard limits early generation to one invoice
     * per period, however wide the window is.
     */
    public function test_a_window_wider_than_the_cycle_still_bills_each_period_once(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $this->setWindow(90);

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 100.00]);

        // Due today, monthly: one month later the item is STILL inside the
        // 90-day window, so without the guard each run would bill another
        // future period.
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $asOf->toDateString()]);
        $item = $this->makeItem($order, $product, [
            'next_billing_date' => $asOf->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $service = app(BillingService::class);
        $first = $service->processRecurringBilling($asOf);
        $second = $service->processRecurringBilling($asOf);
        $third = $service->processRecurringBilling($asOf);

        $this->assertSame(1, $first['invoices_generated']);
        $this->assertSame(0, $second['invoices_generated']);
        $this->assertSame(0, $third['invoices_generated']);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        // Advanced exactly one cycle, not once per run.
        $this->assertSame($asOf->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $item->fresh()->billing_cycles_count);
    }

    /**
     * The generation window boundary is inclusive: an item due exactly
     * today + window is generated today (SQLite stores the date cast as a
     * datetime string, which a plain string comparison excludes).
     */
    public function test_the_window_boundary_is_inclusive(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $this->setWindow(5);

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 100.00]);

        $due = $asOf->addDays(5);
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due->toDateString()]);
        $item = $this->makeItem($order, $product, [
            'next_billing_date' => $due->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame($due->toDateString(), $invoice->due_date->toDateString());
        $this->assertSame($due->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
    }

    private function setWindow(int $days): void
    {
        $settings = app(BillingSettings::class);
        $settings->renewal_invoice_days = $days;
        $settings->save();
    }

    /**
     * The window is a typed BillingSettings key: the admin form must persist it
     * through the settings class (not the legacy settings table), and the
     * frozen 0–90 validation must be live.
     */
    public function test_admin_settings_form_persists_the_window_as_a_typed_billing_key(): void
    {
        $admin = $this->makeSettingsAdmin();

        $this->actingAs($admin)
            ->post(route('admin.settings.update'), [
                'settings' => ['renewal_invoice_days' => '7'],
            ])
            ->assertRedirect();

        $this->assertSame(7, app(BillingSettings::class)->renewal_invoice_days);

        $this->actingAs($admin)
            ->post(route('admin.settings.update'), [
                'settings' => ['renewal_invoice_days' => '120'],
            ])
            ->assertSessionHasErrors('settings.renewal_invoice_days');
    }

    private function makeSettingsAdmin(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['settings.view', 'settings.manage'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
            $adminRole->permissions()->syncWithoutDetaching($permission->id);
        }

        $user->assignRole('admin');

        return $user;
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Renewal Window Corp',
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
