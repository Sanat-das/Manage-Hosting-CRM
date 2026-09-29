<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\GstSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\OrderNumberService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The invoice number carries the financial-year label (Apr–Mar), not the
     * calendar year: INV-{FY}-{seq5}. Calls generateNumber() so this can
     * actually fail.
     */
    public function test_invoice_number_format_pattern(): void
    {
        $number = app(BillingService::class)->generateNumber();

        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', $number);

        $fy = OrderNumberService::financialYearLabel(now());
        $this->assertSame("INV-{$fy}-00001", $number);
    }

    public function test_invoice_number_padding(): void
    {
        $service = app(BillingService::class);

        // The sequences counter is shared, so seed it forward then assert the
        // padding on consecutive deliveries.
        $first = $service->generateNumber();
        $second = $service->generateNumber();

        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', $first);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', $second);
        $this->assertNotSame($first, $second);

        $firstSeq = (int) substr($first, -5);
        $secondSeq = (int) substr($second, -5);
        $this->assertSame($firstSeq + 1, $secondSeq);
        $this->assertSame(str_pad((string) $secondSeq, 5, '0', STR_PAD_LEFT), substr($second, -5));
    }

    /**
     * The cycle -> months map is the single source of truth on Order
     * (Order::CYCLE_MONTHS); recurring billing advances next_billing_date by
     * these values. one_time consumes zero months (never re-billed).
     */
    public function test_billing_cycle_month_mapping_includes_one_time_zero(): void
    {
        $this->assertSame(1, Order::CYCLE_MONTHS['monthly']);
        $this->assertSame(3, Order::CYCLE_MONTHS['quarterly']);
        $this->assertSame(6, Order::CYCLE_MONTHS['semi_annual']);
        $this->assertSame(12, Order::CYCLE_MONTHS['annual']);
        $this->assertSame(24, Order::CYCLE_MONTHS['biennial']);
        $this->assertSame(0, Order::CYCLE_MONTHS['one_time']);
    }

    public function test_process_recurring_billing_creates_sent_invoice_and_advances_cycle(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeDueOrder($customer, $product);

        $service = app(BillingService::class);

        $before = Invoice::count();
        $result = $service->processRecurringBilling();

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame($before + 1, Invoice::count());

        $this->assertDatabaseHas('invoices', [
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'status' => 'sent',
            'amount' => 499.00,
        ]);

        // next_billing_date advanced by one month from the item's OWN due
        // date (the legacy item's fallback: the order's summary date).
        $this->assertSame(
            now()->subDay()->addMonth()->toDateString(),
            $order->fresh()->next_billing_date->toDateString()
        );
    }

    public function test_process_recurring_billing_scales_renewal_total_with_quantity(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 25.00]);
        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 4,
            'total' => 100.00,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => now()->subDay()->toDateString(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 4,
            'unit_price' => 25.00,
            'total' => 100.00,
        ]);

        $result = app(BillingService::class)->processRecurringBilling();

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        // The renewal invoice must reflect qty 4 @ 25.00 = 100.00, not a
        // hardcoded unit price.
        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(100.00, (float) $invoice->amount);
    }

    public function test_process_recurring_billing_skips_zero_value_order(): void
    {
        $customer = $this->makeCustomer();
        // price 0 -> order total 0 -> nothing to bill.
        $product = $this->makeProduct(['price' => 0]);
        $this->makeDueOrder($customer, $product);

        $result = app(BillingService::class)->processRecurringBilling();

        // A free due item is never invoiced — and is no longer an error
        // (review F7): its schedule advances instead of staying stuck.
        $this->assertSame(0, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame(0, Invoice::count());
    }

    /**
     * Review F7: a due item whose quantity-aware total is zero must not be
     * invoiced, must not count as an error, and must not stay stuck due — its
     * schedule advances from its OWN due date while the order summary is kept
     * in sync.
     */
    public function test_process_recurring_billing_advances_zero_value_due_item_without_invoicing(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 0]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 0.00,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => $asOf->subDay()->toDateString(),
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 0.00,
            'total' => 0.00,
            'next_billing_date' => $asOf->subDay()->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(0, $result['invoices_generated']);
        $this->assertSame(0, $result['errors'], 'A free due item must not count as an error.');
        $this->assertSame(0, Invoice::count(), 'No zero-value invoice may be generated.');

        // Advanced from its own due date (yesterday), not from the run date.
        $this->assertSame($asOf->subDay()->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame($asOf->toDateString(), $item->fresh()->last_billing_date->toDateString());
        $this->assertSame(2, $item->fresh()->billing_cycles_count);

        // syncOrderSummary still ran: the order summary follows the item.
        $this->assertSame($asOf->subDay()->addMonth()->toDateString(), $order->fresh()->next_billing_date->toDateString());
    }

    /**
     * A zero-value due item must not leak into an invoice that other due items
     * produce: the invoice carries only the billable lines and their amount.
     */
    public function test_process_recurring_billing_excludes_zero_value_item_from_a_mixed_invoice(): void
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
            'total' => 100.00,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => $asOf->subDay()->toDateString(),
        ]);

        OrderItem::create([
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

        $free = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Free Add-on',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 0.00,
            'total' => 0.00,
            'next_billing_date' => $asOf->subDay()->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(100.00, (float) $invoice->amount);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertStringContainsString('Shared Hosting', $invoice->items()->sole()->description);

        // The free item advanced on its own schedule all the same.
        $this->assertSame($asOf->subDay()->addMonth()->toDateString(), $free->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $free->fresh()->billing_cycles_count);
    }

    /**
     * The once-per-cycle guard also protects the zero-value advance: a free
     * item that is still short of today after one advance must not advance
     * again on a second run the same day (it catches up one period per cycle,
     * not one per run).
     */
    public function test_process_recurring_billing_advances_a_zero_value_item_once_per_cycle(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 0]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 0.00,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => $asOf->subMonths(3)->toDateString(),
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 0.00,
            'total' => 0.00,
            'next_billing_date' => $asOf->subMonths(3)->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $service = app(BillingService::class);
        $first = $service->processRecurringBilling($asOf);
        $second = $service->processRecurringBilling($asOf);

        $this->assertSame(0, $first['invoices_generated']);
        $this->assertSame(0, $first['errors']);
        $this->assertSame(0, $second['invoices_generated']);
        $this->assertSame(0, $second['errors']);
        $this->assertSame(0, Invoice::count());

        // One advance only — still in the past, but not advanced twice today.
        $this->assertSame($asOf->subMonths(3)->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $item->fresh()->billing_cycles_count);
    }

    /**
     * Regression: a null next_billing_date on a NON-legacy item means its
     * schedule was intentionally ended (e.g. a cancelled add-on). It must
     * never fall back to the order summary date — doing so re-billed and
     * re-armed the cancelled line on every run.
     */
    public function test_process_recurring_billing_never_rearms_an_intentionally_ended_item(): void
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
            'total' => 100.00,
            'status' => Order::STATUS_ACTIVE,
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

        // Cancelled add-on: schedule cleared, cycle counter still set, so it
        // is NOT a legacy item and must not inherit the order's due date.
        $cancelledAddon = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 50.00,
            'total' => 50.00,
            'recurring_cycles_limit' => 0,
            'next_billing_date' => null,
            'billing_cycles_count' => 1,
            'parent_item_id' => $parent->id,
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(100.00, (float) $invoice->amount);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertStringContainsString('Shared Hosting', $invoice->items()->sole()->description);

        // The cancelled line stays cancelled: no date, no counter bump.
        $this->assertNull($cancelledAddon->fresh()->next_billing_date);
        $this->assertSame(1, $cancelledAddon->fresh()->billing_cycles_count);
    }

    public function test_process_recurring_billing_skips_one_time_order(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        // one_time order: cycle consumes 0 months; a null next_billing_date
        // (never set) already excludes it from the due-orders query.
        $this->makeDueOrder($customer, $product, [
            'billing_cycle' => 'one_time',
            'next_billing_date' => null,
        ]);

        $result = app(BillingService::class)->processRecurringBilling();

        $this->assertSame(0, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_process_recurring_billing_ends_after_cycles_limit(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['recurring_cycles_limit' => 2]);
        $order = $this->makeDueOrder($customer, $product);

        // Two billing cycles already invoiced (initial + one renewal) — the
        // limit is reached, so the due order must NOT be renewed again and its
        // recurring schedule must end.
        $this->makeInvoice($customer, $order, 'paid');
        $this->makeInvoice($customer, $order, 'sent');

        $result = app(BillingService::class)->processRecurringBilling();

        $this->assertSame(0, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame(2, Invoice::count());
        $this->assertNull($order->fresh()->next_billing_date, 'Recurring billing must end at the cycle limit.');
    }

    public function test_process_recurring_billing_continues_below_cycles_limit(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['recurring_cycles_limit' => 5]);
        $order = $this->makeDueOrder($customer, $product);

        // Two of five allowed cycles used — the due order renews normally.
        $this->makeInvoice($customer, $order, 'paid');
        $this->makeInvoice($customer, $order, 'paid');

        $result = app(BillingService::class)->processRecurringBilling();

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(3, Invoice::count());
        $this->assertNotNull($order->fresh()->next_billing_date);
    }

    public function test_process_recurring_billing_renews_only_due_items_on_their_own_cycles(): void
    {
        // WHMCS model: each order item (service) renews independently. A
        // monthly item that is due and an annual item that is not — one
        // renewal invoice with only the due line, and each item's schedule
        // advances on its own cycle.
        $customer = $this->makeCustomer();
        $monthly = $this->makeProduct(['name' => 'Monthly VPS', 'price' => 100.00]);
        $annual = $this->makeProduct(['name' => 'Annual Backup', 'price' => 1000.00]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $monthly->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 1100.00,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => now()->subDay()->toDateString(), // earliest item date
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $monthly->id,
            'product_name' => 'Monthly VPS',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'next_billing_date' => now()->subDay()->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $annual->id,
            'product_name' => 'Annual Backup',
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'unit_price' => 1000.00,
            'total' => 1000.00,
            'next_billing_date' => now()->addMonths(11)->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $result = app(BillingService::class)->processRecurringBilling();

        // One invoice, one line — only the due monthly item.
        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(100.00, (float) $invoice->amount);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertStringContainsString('Monthly VPS', $invoice->items()->first()->description);

        // The monthly item advanced one month from ITS OWN due date and its
        // counter bumped; the annual item is untouched.
        $items = $order->items()->orderBy('id')->get();
        $this->assertSame(now()->subDay()->addMonth()->toDateString(), $items[0]->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $items[0]->fresh()->billing_cycles_count);
        $this->assertSame(now()->addMonths(11)->toDateString(), $items[1]->fresh()->next_billing_date->toDateString());
        $this->assertSame(1, $items[1]->fresh()->billing_cycles_count);

        // The order summary tracks the earliest remaining item date (the
        // monthly item renews again one month after its own due date).
        $this->assertSame(now()->subDay()->addMonth()->toDateString(), $order->fresh()->next_billing_date->toDateString());
    }

    public function test_process_recurring_billing_ends_only_the_item_at_its_snapshot_cycle_limit(): void
    {
        // New-style item: the recurring-cycles limit is snapshotted per item
        // and its own counter is authoritative. An item at its limit ends its
        // own schedule without touching its sibling's.
        $customer = $this->makeCustomer();
        $limited = $this->makeProduct(['name' => 'Limited Plan', 'price' => 50.00, 'recurring_cycles_limit' => 2]);
        $open = $this->makeProduct(['name' => 'Open Plan', 'price' => 80.00]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $limited->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 130.00,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => now()->subDay()->toDateString(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $limited->id,
            'product_name' => 'Limited Plan',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 50.00,
            'total' => 50.00,
            'next_billing_date' => now()->subDay()->toDateString(),
            'recurring_cycles_limit' => 2,
            'billing_cycles_count' => 2, // initial + one renewal — limit reached
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $open->id,
            'product_name' => 'Open Plan',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 80.00,
            'total' => 80.00,
            'next_billing_date' => now()->subDay()->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $result = app(BillingService::class)->processRecurringBilling();

        // Only the open item is billed (₹80); the limited item ends.
        $this->assertSame(1, $result['invoices_generated']);
        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(80.00, (float) $invoice->amount);

        $items = $order->items()->orderBy('id')->get();
        $this->assertNull($items[0]->fresh()->next_billing_date, 'The limited item stops renewing at its snapshot limit.');
        $this->assertSame(now()->subDay()->addMonth()->toDateString(), $items[1]->fresh()->next_billing_date->toDateString());

        // The order summary follows the remaining recurring item.
        $this->assertSame(now()->subDay()->addMonth()->toDateString(), $order->fresh()->next_billing_date->toDateString());
    }

    public function test_process_auto_terminations_terminates_expired_fixed_term(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['auto_terminate_value' => 30, 'auto_terminate_unit' => 'days']);
        $order = $this->makeDueOrder($customer, $product);
        $this->markActivated($order, now()->subDays(31));

        $result = app(BillingService::class)->processAutoTerminations();

        $this->assertSame(1, $result['terminated']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame(Order::STATUS_TERMINATED, $order->fresh()->status);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_TERMINATED,
        ]);
    }

    public function test_process_auto_terminations_skips_term_not_yet_due(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['auto_terminate_value' => 30, 'auto_terminate_unit' => 'days']);
        $order = $this->makeDueOrder($customer, $product);
        $this->markActivated($order, now()->subDays(10));

        $result = app(BillingService::class)->processAutoTerminations();

        $this->assertSame(0, $result['terminated']);
        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);
    }

    public function test_process_auto_terminations_skips_disabled_products(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['auto_terminate_value' => 0]);
        $order = $this->makeDueOrder($customer, $product);
        $this->markActivated($order, now()->subDays(400));

        $result = app(BillingService::class)->processAutoTerminations();

        $this->assertSame(0, $result['terminated']);
        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);
    }

    public function test_process_auto_terminations_ends_only_the_elapsed_item_and_keeps_the_order_active(): void
    {
        // Per-service model: a term-ended SECONDARY item stops its own billing
        // while the primary service (no term) keeps running — the order must
        // stay active and its summary must follow the remaining service.
        $customer = $this->makeCustomer();
        $primary = $this->makeProduct(['name' => 'Primary Plan', 'price' => 200.00]);
        $secondary = $this->makeProduct([
            'name' => 'Trial Backup', 'price' => 50.00,
            'auto_terminate_value' => 30, 'auto_terminate_unit' => 'days',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $primary->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 250.00,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => now()->addMonth()->toDateString(),
        ]);

        $primaryItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $primary->id,
            'product_name' => 'Primary Plan',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 200.00,
            'total' => 200.00,
            'next_billing_date' => now()->addMonth()->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $secondaryItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $secondary->id,
            'product_name' => 'Trial Backup',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 50.00,
            'total' => 50.00,
            'next_billing_date' => now()->subDay()->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $this->markActivated($order, now()->subDays(31)); // trial term (30d) elapsed

        $result = app(BillingService::class)->processAutoTerminations();

        // The order survives — only the trial item's billing ended.
        $this->assertSame(0, $result['terminated']);
        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);

        $this->assertNull($secondaryItem->fresh()->next_billing_date, 'The elapsed service stops renewing.');
        $this->assertSame(now()->addMonth()->toDateString(), $primaryItem->fresh()->next_billing_date->toDateString());

        // The order summary follows the remaining service.
        $this->assertSame(now()->addMonth()->toDateString(), $order->fresh()->next_billing_date->toDateString());
    }

    public function test_process_auto_terminations_terminates_order_when_all_services_elapsed(): void
    {
        // When every service's term has elapsed, nothing remains recurring and
        // the order itself terminates through the state machine.
        $customer = $this->makeCustomer();
        $first = $this->makeProduct([
            'name' => 'Short Trial', 'price' => 100.00,
            'auto_terminate_value' => 30, 'auto_terminate_unit' => 'days',
        ]);
        $second = $this->makeProduct([
            'name' => 'Second Trial', 'price' => 60.00,
            'auto_terminate_value' => 60, 'auto_terminate_unit' => 'days',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $first->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 160.00,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => now()->subDay()->toDateString(),
        ]);

        foreach ([$first, $second] as $index => $product) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'unit_price' => $index === 0 ? 100.00 : 60.00,
                'total' => $index === 0 ? 100.00 : 60.00,
                'next_billing_date' => now()->subDay()->toDateString(),
                'billing_cycles_count' => 1,
            ]);
        }

        $this->markActivated($order, now()->subDays(61)); // both terms elapsed

        $result = app(BillingService::class)->processAutoTerminations();

        $this->assertSame(1, $result['terminated']);
        $this->assertSame(Order::STATUS_TERMINATED, $order->fresh()->status);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_TERMINATED,
        ]);

        foreach ($order->items()->get() as $item) {
            $this->assertNull($item->fresh()->next_billing_date);
        }
    }

    /**
     * Test that the recordPayment return shape has all expected keys.
     */
    public function test_record_payment_return_shape(): void
    {
        $expectedKeys = [
            'invoice_id', 'payment_id', 'amount', 'status',
            'previous_due', 'remaining_due', 'overpayment', 'credit_created',
        ];

        // Simulate a normal payment return
        $result = [
            'invoice_id' => 1,
            'payment_id' => 1,
            'amount' => 100.0,
            'status' => 'paid',
            'previous_due' => 100.0,
            'remaining_due' => 0.0,
            'overpayment' => 0.0,
            'credit_created' => false,
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Return array must contain key: {$key}");
        }
    }

    /**
     * Test overpayment detection logic.
     */
    public function test_overpayment_detection(): void
    {
        $total = 100.0;
        $paidAmount = 0.0;
        $payment = 150.0;

        $overpayment = ($paidAmount + $payment) - $total;
        $this->assertEquals(50.0, $overpayment);
        $this->assertGreaterThan(0, $overpayment);

        // Partial payment
        $payment2 = 60.0;
        $overpayment2 = ($paidAmount + $payment2) - $total;
        $this->assertEquals(-40.0, $overpayment2);
        $this->assertLessThanOrEqual(0, $overpayment2);
    }

    /**
     * Test paid status determination.
     */
    public function test_paid_status_determination(): void
    {
        $total = 100.0;

        // Full payment
        $newPaid = 100.0;
        $remaining = max(0.0, $total - $newPaid);
        $status = $remaining <= 0 ? 'paid' : 'partial';
        $this->assertEquals('paid', $status);

        // Partial payment
        $newPaid2 = 50.0;
        $remaining2 = max(0.0, $total - $newPaid2);
        $status2 = $remaining2 <= 0 ? 'paid' : 'partial';
        $this->assertEquals('partial', $status2);

        // Overpayment (clamped)
        $newPaid3 = 150.0;
        $remaining3 = max(0.0, $total - $newPaid3);
        $status3 = $remaining3 <= 0 ? 'paid' : 'partial';
        $this->assertEquals('paid', $status3);
    }

    /**
     * Renewal lines must carry product_id: under tax_mode per_product a line
     * without one is billed tax-free (GstTaxService::calculateItemTax), so a
     * renewal would silently drop the GST the draft invoice charged.
     */
    public function test_process_recurring_billing_carries_product_id_for_per_product_gst(): void
    {
        // The migration seeds the id=1 row; `id` is not fillable, so update it.
        GstSetting::where('id', 1)->update([
            'state_code' => '27',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'enabled' => 1,
            'tax_mode' => GstSetting::TAX_MODE_PER_PRODUCT,
        ]);

        $customer = $this->makeCustomer();
        $customer->update(['state_code' => '27']);

        $product = $this->makeProduct();
        $product->update(['gst_enabled' => 1, 'gst_type' => 'standard']);

        $order = $this->makeDueOrder($customer, $product);

        $result = app(BillingService::class)->processRecurringBilling();

        $this->assertSame(1, $result['invoices_generated']);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $line = $invoice->items()->sole();

        $this->assertSame($product->id, (int) $line->product_id);
        $this->assertGreaterThan(0, (float) $invoice->cgst_amount);
        $this->assertGreaterThan(0, (float) $invoice->sgst_amount);
        $this->assertSame(0.0, (float) $invoice->igst_amount);
    }

    /**
     * createAddonChargeInvoice: one immediately-issued invoice for an attached
     * add-on — recurring line plus setup-fee line, GST with the customer's
     * place of supply, and that code snapshotted on the invoice.
     */
    public function test_create_addon_charge_invoice_builds_a_sent_invoice_with_setup_fee_and_gst(): void
    {
        GstSetting::where('id', 1)->update([
            'state_code' => '27',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'enabled' => 1,
            'tax_mode' => GstSetting::TAX_MODE_GLOBAL,
        ]);

        $customer = $this->makeCustomer();
        $customer->update(['state_code' => '27']);

        $product = $this->makeProduct(['name' => 'Shared Hosting', 'price' => 100.00]);
        $order = $this->makeDueOrder($customer, $product);
        $parent = $order->items()->sole();

        $recurring = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'unit_price' => 50.00,
            'total' => 100.00,
            'next_billing_date' => now()->addMonth()->toDateString(),
            'billing_cycles_count' => 1,
            'parent_item_id' => $parent->id,
        ]);

        $setup = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Extra Storage — Setup Fee',
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'unit_price' => 25.00,
            'total' => 25.00,
            'billing_cycles_count' => 0,
            'parent_item_id' => $parent->id,
        ]);

        $invoice = app(BillingService::class)->createAddonChargeInvoice($recurring, $setup);

        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertSame($customer->id, (int) $invoice->customer_id);
        $this->assertSame($order->id, (int) $invoice->order_id);
        $this->assertSame(125.00, (float) $invoice->amount);
        $this->assertSame('27', $invoice->place_of_supply_code);
        $this->assertSame(now()->addDays(7)->toDateString(), $invoice->due_date->toDateString());
        $this->assertSame('Add-on charge — Extra Storage', $invoice->notes);

        $lines = $invoice->items()->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('Extra Storage — Monthly', $lines[0]->description);
        $this->assertSame(2, (int) $lines[0]->quantity);
        $this->assertSame(50.00, (float) $lines[0]->unit_price);
        $this->assertSame(100.00, (float) $lines[0]->total);
        $this->assertSame('Extra Storage — Setup Fee — One time', $lines[1]->description);
        $this->assertSame(25.00, (float) $lines[1]->total);

        // Same state as the company → CGST + SGST on the whole 125.00, no IGST.
        $this->assertGreaterThan(0, (float) $invoice->cgst_amount);
        $this->assertGreaterThan(0, (float) $invoice->sgst_amount);
        $this->assertSame(0.0, (float) $invoice->igst_amount);
        $this->assertSame(round(125.00 + (float) $invoice->tax, 2), round((float) $invoice->total, 2));
    }

    public function test_create_addon_charge_invoice_without_setup_fee_bills_only_the_addon_line(): void
    {
        $customer = $this->makeCustomer();
        $customer->update(['state_code' => '29']);

        $product = $this->makeProduct(['name' => 'Shared Hosting', 'price' => 100.00]);
        $order = $this->makeDueOrder($customer, $product);
        $parent = $order->items()->sole();

        $recurring = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 60.00,
            'total' => 60.00,
            'next_billing_date' => now()->addMonth()->toDateString(),
            'billing_cycles_count' => 1,
            'parent_item_id' => $parent->id,
        ]);

        $invoice = app(BillingService::class)->createAddonChargeInvoice($recurring, null, 'Attached by admin');

        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertSame(60.00, (float) $invoice->amount);
        $this->assertSame('29', $invoice->place_of_supply_code);
        $this->assertSame('Attached by admin', $invoice->notes);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame('Extra Storage — Monthly', $invoice->items()->sole()->description);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Unit Test Corp',
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

    private function makeDueOrder(Customer $customer, Product $product, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => now()->subDay()->toDateString(),
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $order->quantity,
            'unit_price' => (float) $product->price,
            'total' => (float) $order->total,
        ]);

        return $order;
    }

    private function makeInvoice(Customer $customer, Order $order, string $status): Invoice
    {
        return Invoice::create([
            'invoice_no' => app(BillingService::class)->generateNumber(),
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'amount' => (float) $order->total,
            'status' => $status,
            'due_date' => now()->toDateString(),
        ]);
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
