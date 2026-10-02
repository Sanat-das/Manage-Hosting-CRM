<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\UpgradeQuoteService;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Billing bridge (plan §6): paying an upgrade invoice materializes the
 * upgrade (ApplyUpgradeOnInvoicePaid), the recurring-billing run cancels an
 * unpaid invoiced upgrade exactly when it generates the service's renewal
 * invoice, and paying a renewal invoice never applies any upgrade.
 */
class UpgradeBillingBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // spatie registers settings classes as container-scoped singletons;
        // flush them so each test resolves a fresh instance for its own DB.
        app()->forgetScopedInstances();

        // AppSettings caches the legacy settings table statically for the
        // request lifetime; reset so each test reads freshly-seeded rows.
        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    private function makeProduct(string $name, float $price): Product
    {
        $product = Product::create([
            'name' => $name,
            'is_bundle' => false,
            'price' => $price,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ]);

        $product->pricing()->create([
            'billing_cycle' => 'monthly',
            'price' => $price,
            'setup_fee' => 0,
        ]);

        return $product;
    }

    private function makeOrder(Product $from, float $unitPrice, array $overrides = [], array $itemOverrides = []): Order
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'company' => 'Upgrade Bridge Corp',
            'status' => 'active',
        ]);

        $order = Order::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $from->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => $unitPrice,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => '2026-02-15',
            'last_billing_date' => '2026-01-16',
        ], $overrides));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'product_id' => $from->id,
            'product_name' => $from->name,
            'billing_cycle' => $order->billing_cycle,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'total' => $unitPrice,
            'next_billing_date' => $order->next_billing_date?->toDateString(),
        ], $itemOverrides));

        return $order;
    }

    private function quote(Order $order, Product $to): array
    {
        return app(UpgradeQuoteService::class)->quote($order, $to, CarbonImmutable::parse('2026-01-26'));
    }

    private function makeRequest(Order $order, Product $from, Product $to, array $quote, array $overrides = []): UpgradeRequest
    {
        return UpgradeRequest::create(array_merge([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'upgrade_type' => 'product',
            'status' => UpgradeRequest::STATUS_PENDING,
            'billing_cycle' => $order->billing_cycle,
            'credited' => $quote['credited'],
            'debited' => $quote['debited'],
            'setup_fee' => $quote['setup_fee_difference'],
            'payable' => $quote['payable'],
            'credit_amount' => $quote['credit'],
            'proration_days' => $quote['proration_days'],
            'period_days' => $quote['period_days'],
        ], $overrides));
    }

    /**
     * Mirror UpgradeRequestService::approve's invoicing step: a sent invoice
     * for the payable amount, linked to the order, one upgrade line.
     */
    private function invoiceUpgrade(Order $order, Product $to, float $payable): Invoice
    {
        return app(BillingService::class)->createWithItems([
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'amount' => $payable,
            'status' => Invoice::STATUS_SENT,
            'due_date' => CarbonImmutable::parse('2026-02-02')->toDateString(),
            'notes' => 'Upgrade '.$order->order_number,
        ], [[
            'description' => 'Upgrade to '.$to->name,
            'quantity' => 1,
            'unit_price' => $payable,
            'total' => $payable,
            'product_id' => $to->id,
        ]]);
    }

    public function test_paying_the_upgrade_invoice_applies_the_request(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $order = $this->makeOrder($from, 100);
        $item = $order->items()->sole();

        $quote = $this->quote($order, $to);
        $invoice = $this->invoiceUpgrade($order, $to, $quote['payable']);
        $request = $this->makeRequest($order, $from, $to, $quote, [
            'invoice_id' => $invoice->id,
            'approved_at' => '2026-01-26 10:00:00',
        ]);

        app(BillingService::class)->markPaid($invoice->id, (float) $invoice->total, 'bank_transfer');

        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->applied_at);

        $served = $item->fresh();
        $this->assertSame($to->id, $served->product_id);
        $this->assertSame(200.0, (float) $served->unit_price);
        $this->assertSame(200.0, (float) $served->total);
        // next_billing_date is preserved through the change.
        $this->assertSame('2026-02-15', $served->next_billing_date->toDateString());
    }

    public function test_renewal_run_cancels_unpaid_upgrade_and_bills_current_details(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');

        $from = $this->makeProduct('Basic Hosting', 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $order = $this->makeOrder($from, 100, [
            'next_billing_date' => $asOf->toDateString(),
        ], [
            'next_billing_date' => $asOf->toDateString(),
            'billing_cycles_count' => 1,
        ]);
        $item = $order->items()->sole();

        $quote = $this->quote($order, $to);
        $upgradeInvoice = $this->invoiceUpgrade($order, $to, $quote['payable']);
        $request = $this->makeRequest($order, $from, $to, $quote, [
            'invoice_id' => $upgradeInvoice->id,
            'approved_at' => '2026-01-26 10:00:00',
        ]);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        // The renewal invoiced the OLD configuration, and its existence is the
        // hook that cancelled the unpaid upgrade.
        $renewal = Invoice::where('order_id', $order->id)
            ->where('notes', 'Auto-generated renewal invoice')
            ->sole();
        $this->assertSame(Invoice::STATUS_SENT, $renewal->status);
        $this->assertSame(100.0, (float) $renewal->amount);
        $this->assertSame($asOf->toDateString(), $renewal->due_date->toDateString());

        $cancelled = $request->fresh();
        $this->assertSame(UpgradeRequest::STATUS_CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertStringContainsString('renewal invoice generated while the upgrade was unpaid', (string) $cancelled->notes);

        // The upgrade never materialized: the served item keeps the OLD plan.
        $served = $item->fresh();
        $this->assertSame($from->id, $served->product_id);
        $this->assertSame(100.0, (float) $served->unit_price);
    }

    public function test_renewal_run_keeps_applied_and_awaiting_approval_requests(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');

        $from = $this->makeProduct('Basic Hosting', 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $order = $this->makeOrder($from, 100, [
            'next_billing_date' => $asOf->toDateString(),
        ], [
            'next_billing_date' => $asOf->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $quote = $this->quote($order, $to);

        $applied = $this->makeRequest($order, $from, $to, $quote, [
            'status' => UpgradeRequest::STATUS_APPLIED,
            'applied_at' => '2026-01-26 11:00:00',
        ]);

        // Awaiting approval: no invoice, no approved_at — must survive the hook.
        $awaiting = $this->makeRequest($order, $from, $to, $quote);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame(1, Invoice::where('order_id', $order->id)
            ->where('notes', 'Auto-generated renewal invoice')
            ->count());

        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $applied->fresh()->status);
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $awaiting->fresh()->status);
    }

    public function test_renewal_invoice_payment_does_not_apply_any_upgrade(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');

        $from = $this->makeProduct('Basic Hosting', 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $order = $this->makeOrder($from, 100, [
            'next_billing_date' => $asOf->toDateString(),
        ], [
            'next_billing_date' => $asOf->toDateString(),
            'billing_cycles_count' => 1,
        ]);
        $item = $order->items()->sole();

        // Awaiting approval: pending, no invoice — NOT cancelled by the cron,
        // and its invoice_id does not match the renewal invoice.
        $quote = $this->quote($order, $to);
        $request = $this->makeRequest($order, $from, $to, $quote);

        app(BillingService::class)->processRecurringBilling($asOf);

        $renewal = Invoice::where('order_id', $order->id)
            ->where('notes', 'Auto-generated renewal invoice')
            ->sole();

        app(BillingService::class)->markPaid($renewal->id, (float) $renewal->total, 'bank_transfer');

        $this->assertSame(Invoice::STATUS_PAID, $renewal->fresh()->status);

        // No false match: the request is untouched, nothing was applied.
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertNull($request->fresh()->applied_at);

        $served = $item->fresh();
        $this->assertSame($from->id, $served->product_id);
        $this->assertSame(100.0, (float) $served->unit_price);
    }
}
