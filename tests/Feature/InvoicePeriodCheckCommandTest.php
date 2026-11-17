<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Notifications\InvoicePeriodUntrackedNotification;
use App\Services\Billing\InvoicePeriodAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * invoices:period-check finds the invoice lines that carry no billing period.
 *
 * A recurring service's schedule is driven by two columns on every invoice
 * line (billed_due_date / billed_cycle_months) plus the order item the line
 * bills. A line without a period is invisible to the schedule machinery: it
 * neither blocks a renewal nor advances the schedule when it is paid. The
 * check makes the class visible — it reports those lines and, with --fix,
 * repairs the ones that resolve to exactly one live-recurring order item.
 */
class InvoicePeriodCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The de-duplication guard lives in the cache; an entry left by
        // another test would silence the alert this file asserts on.
        Cache::flush();
    }

    // ── (1) an open invoice line without a period is reported ────────

    public function test_an_open_invoice_line_without_a_period_is_reported(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $item = $this->makeLiveItem($order, $product);

        $invoice = $this->makeInvoice($customer, $order);
        $this->makeLine($invoice, ['order_item_id' => $item->id]);

        [$exitCode, $output] = $this->runCheck();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($invoice->invoice_no, $output);
        $this->assertStringContainsString('Findings', $output);
        $this->assertStringContainsString('Repair: automatic', $output);
    }

    // ── (2) half a period is as invisible as none ────────────────────

    public function test_a_line_with_a_due_date_but_no_cycle_is_reported(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $item = $this->makeLiveItem($order, $product);

        $invoice = $this->makeInvoice($customer, $order);
        $this->makeLine($invoice, [
            'order_item_id' => $item->id,
            'billed_due_date' => '2026-11-04',
            'billed_cycle_months' => null,
        ]);

        [$exitCode, $output] = $this->runCheck();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($invoice->invoice_no, $output);
        $this->assertStringContainsString('Findings', $output);
    }

    // ── (3) a settled invoice is history, not an open demand ─────────

    public function test_a_paid_invoice_is_not_reported(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $item = $this->makeLiveItem($order, $product);

        $invoice = $this->makeInvoice($customer, $order, [
            'status' => Invoice::STATUS_PAID,
            'paid_amount' => 100.00,
        ]);
        $this->makeLine($invoice, ['order_item_id' => $item->id]);

        [$exitCode, $output] = $this->runCheck();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString($invoice->invoice_no, $output);
        $this->assertStringContainsString('Nothing found', $output);
    }

    // ── (4) a draft was never sent: listed, but not a finding ────────

    public function test_a_draft_invoice_is_listed_as_a_draft_but_is_not_a_finding(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $item = $this->makeLiveItem($order, $product);

        $invoice = $this->makeInvoice($customer, $order, ['status' => Invoice::STATUS_DRAFT]);
        $this->makeLine($invoice, ['order_item_id' => $item->id]);

        [$exitCode, $output] = $this->runCheck();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Drafts — not raised to the customer', $output);
        $this->assertStringContainsString($invoice->invoice_no, $output);
        $this->assertStringNotContainsString('Findings', $output);
    }

    // ── (5) an upgrade invoice is a prorated one-off charge ──────────

    public function test_an_upgrade_invoice_is_not_reported(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $target = $this->makeProduct(['name' => 'Business Hosting']);
        $order = $this->makeOrder($customer, $product);
        $item = $this->makeLiveItem($order, $product);

        $invoice = $this->makeInvoice($customer, $order);
        $this->makeLine($invoice, ['order_item_id' => $item->id]);

        UpgradeRequest::create([
            'upgrade_no' => 'UPG-TEST-1',
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'from_product_id' => $product->id,
            'to_product_id' => $target->id,
            'upgrade_type' => 'product',
            'status' => UpgradeRequest::STATUS_PENDING,
            'billing_cycle' => 'monthly',
        ]);

        [$exitCode, $output] = $this->runCheck();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString($invoice->invoice_no, $output);
    }

    // ── (6) a setup fee on a one_time item carries no period ─────────

    public function test_a_setup_fee_line_on_a_one_time_item_is_not_reported(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeLiveItem($order, $product);

        $setup = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Extra Backup — Setup Fee',
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'unit_price' => 50.00,
            'total' => 50.00,
            'recurring_cycles_limit' => 0,
            'next_billing_date' => null,
            'next_invoice_date' => null,
            'billing_cycles_count' => 0,
        ]);

        $invoice = $this->makeInvoice($customer, $order);
        $this->makeLine($invoice, ['order_item_id' => $setup->id, 'description' => 'Extra Backup — Setup Fee']);

        [$exitCode, $output] = $this->runCheck();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString($invoice->invoice_no, $output);
    }

    // ── (7) no live recurring service: no period is expected ─────────

    public function test_an_invoice_on_an_order_without_a_live_recurring_item_is_not_reported(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        // An order whose only service has ended its schedule.
        $endedOrder = $this->makeOrder($customer, $product);
        $this->makeItem($endedOrder, $product, [
            'billing_cycle' => 'annual',
            'next_billing_date' => null,
            'next_invoice_date' => null,
            'billing_cycles_count' => 2,
        ]);
        $endedInvoice = $this->makeInvoice($customer, $endedOrder);
        $this->makeLine($endedInvoice);

        // An order with no items at all.
        $emptyOrder = $this->makeOrder($customer, $product);
        $emptyInvoice = $this->makeInvoice($customer, $emptyOrder);
        $this->makeLine($emptyInvoice);

        [$exitCode, $output] = $this->runCheck();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString($endedInvoice->invoice_no, $output);
        $this->assertStringNotContainsString($emptyInvoice->invoice_no, $output);
        $this->assertStringContainsString('Nothing found', $output);
    }

    // ── (8) --fix attaches the period and moves the generation pointer ──

    public function test_fix_attaches_the_period_and_advances_the_generation_pointer(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $item = $this->makeLiveItem($order, $product);

        $invoice = $this->makeInvoice($customer, $order);
        $line = $this->makeLine($invoice, ['order_item_id' => $item->id]);

        [$exitCode, $output] = $this->runCheck(['--fix' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Repaired', $output);

        $line->refresh();
        $this->assertSame('2026-11-04', $line->billed_due_date->toDateString());
        $this->assertSame(12, (int) $line->billed_cycle_months);

        // The generation pointer moves one cycle; the pay-by date waits for
        // the money.
        $item->refresh();
        $this->assertSame('2027-11-04', $item->next_invoice_date->toDateString());
        $this->assertSame('2026-11-04', $item->next_billing_date->toDateString());

        // The order summary is re-synced with the items.
        $order->refresh();
        $this->assertSame('2027-11-04', $order->next_invoice_date->toDateString());
        $this->assertSame('2026-11-04', $order->next_billing_date->toDateString());

        // The repair is on the live audit trail.
        $log = ActivityLog::where('action', InvoicePeriodAudit::ACTIVITY_ACTION)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame((int) $invoice->id, (int) $log->metadata['invoice_id']);
        $this->assertSame((int) $item->id, (int) $log->metadata['order_item_id']);
        $this->assertSame('2026-11-04', $log->metadata['billed_due_date']);
        $this->assertSame(12, (int) $log->metadata['billed_cycle_months']);
        $this->assertStringContainsString($invoice->invoice_no, (string) $log->description);
    }

    public function test_fix_falls_back_to_the_order_cycle_when_the_item_cycle_is_null(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, ['billing_cycle' => 'annual']);
        $item = $this->makeLiveItem($order, $product, ['billing_cycle' => null]);

        $invoice = $this->makeInvoice($customer, $order);
        $line = $this->makeLine($invoice, ['order_item_id' => $item->id]);

        [$exitCode] = $this->runCheck(['--fix' => true]);

        $this->assertSame(0, $exitCode);

        $line->refresh();
        $this->assertSame(12, (int) $line->billed_cycle_months);

        $item->refresh();
        $this->assertSame('2027-11-04', $item->next_invoice_date->toDateString());
    }

    // ── (8b) --fix links the line to the item it was attributed to ─────

    public function test_fix_links_an_unlinked_line_to_the_item_it_bills(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $item = $this->makeLiveItem($order, $product);

        // A legacy line that names no order item at all; the order has exactly
        // one live-recurring item, so it is attributable and repairable.
        $invoice = $this->makeInvoice($customer, $order);
        $line = $this->makeLine($invoice);

        [$exitCode, $output] = $this->runCheck(['--fix' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Repaired', $output);

        $line->refresh();

        // The EXACT link is written: the paid advance then resolves the item
        // without the ambiguous product/date fallback.
        $this->assertSame((int) $item->id, (int) $line->order_item_id);
        $this->assertSame('2026-11-04', $line->billed_due_date->toDateString());
        $this->assertSame(12, (int) $line->billed_cycle_months);

        $item->refresh();

        $this->assertSame('2027-11-04', $item->next_invoice_date->toDateString());
        $this->assertSame('2026-11-04', $item->next_billing_date->toDateString());
    }

    // ── (9) --fix never repairs an unattributable finding ────────────

    public function test_fix_leaves_an_unattributable_finding_untouched(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $first = $this->makeLiveItem($order, $product);
        $second = $this->makeLiveItem($order, $product, ['product_name' => 'Business Hosting']);

        $invoice = $this->makeInvoice($customer, $order);
        $line = $this->makeLine($invoice);

        [$exitCode, $output] = $this->runCheck(['--fix' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('UNATTRIBUTABLE', $output);
        $this->assertStringContainsString('Not repaired', $output);

        $line->refresh();
        $this->assertNull($line->billed_due_date);
        $this->assertNull($line->billed_cycle_months);

        $this->assertSame('2026-11-04', $first->fresh()->next_invoice_date->toDateString());
        $this->assertSame('2026-11-04', $second->fresh()->next_invoice_date->toDateString());

        $this->assertSame(0, ActivityLog::where('action', InvoicePeriodAudit::ACTIVITY_ACTION)->count());
    }

    // ── (10) without --fix nothing is mutated ────────────────────────

    public function test_without_the_fix_flag_nothing_is_mutated(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $item = $this->makeLiveItem($order, $product);

        $invoice = $this->makeInvoice($customer, $order);
        $line = $this->makeLine($invoice, ['order_item_id' => $item->id]);

        [$exitCode] = $this->runCheck();

        $this->assertSame(0, $exitCode);

        $line->refresh();
        $this->assertNull($line->billed_due_date);
        $this->assertNull($line->billed_cycle_months);

        $item->refresh();
        $this->assertSame('2026-11-04', $item->next_invoice_date->toDateString());
        $this->assertSame('2026-11-04', $item->next_billing_date->toDateString());

        $this->assertSame(0, ActivityLog::where('action', InvoicePeriodAudit::ACTIVITY_ACTION)->count());
    }

    // ── the staff pool is told once per finding per day ─────────────

    public function test_the_staff_pool_is_notified_once_per_finding_per_day(): void
    {
        Notification::fake();

        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $item = $this->makeLiveItem($order, $product);

        $invoice = $this->makeInvoice($customer, $order);
        $this->makeLine($invoice, ['order_item_id' => $item->id]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        [$exitCode] = $this->runCheck();

        $this->assertSame(0, $exitCode);

        Notification::assertSentTo($admin, InvoicePeriodUntrackedNotification::class);
        Notification::assertSentToTimes($admin, InvoicePeriodUntrackedNotification::class, 1);

        $notification = Notification::sent($admin, InvoicePeriodUntrackedNotification::class)->first();
        $this->assertSame((int) $invoice->id, (int) $notification->invoice->id);
        $this->assertSame(1, (int) $notification->findingsCount);

        // A second run in the same day does not alert the same finding again.
        [$exitCode] = $this->runCheck();

        $this->assertSame(0, $exitCode);
        Notification::assertSentToTimes($admin, InvoicePeriodUntrackedNotification::class, 1);
    }

    // ── the alert lands in the staff inbox ────────────────────────────

    public function test_the_alert_lands_in_the_staff_notifications_inbox(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $item = $this->makeLiveItem($order, $product);

        $invoice = $this->makeInvoice($customer, $order);
        $this->makeLine($invoice, ['order_item_id' => $item->id]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        [$exitCode] = $this->runCheck();

        $this->assertSame(0, $exitCode);

        $this->actingAs($admin)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee($invoice->invoice_no);
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * @return array{0: int, 1: string}
     */
    private function runCheck(array $parameters = []): array
    {
        $exitCode = Artisan::call('invoices:period-check', $parameters);

        return [$exitCode, Artisan::output()];
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Period Check Corp',
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
            'next_billing_date' => '2026-11-04',
            'next_invoice_date' => '2026-11-04',
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

    /**
     * An order item whose schedule is still armed.
     */
    private function makeLiveItem(Order $order, Product $product, array $overrides = []): OrderItem
    {
        return $this->makeItem($order, $product, array_merge([
            'billing_cycle' => 'annual',
            'next_billing_date' => '2026-11-04',
            'next_invoice_date' => '2026-11-04',
            'billing_cycles_count' => 1,
        ], $overrides));
    }

    private function makeInvoice(Customer $customer, ?Order $order, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_no' => 'INV-'.date('Y').'-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'order_id' => $order?->id,
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $overrides));
    }

    private function makeLine(Invoice $invoice, array $overrides = []): InvoiceItem
    {
        return InvoiceItem::create(array_merge([
            'invoice_id' => $invoice->id,
            'description' => 'Shared Hosting - Annual',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
        ], $overrides));
    }
}
