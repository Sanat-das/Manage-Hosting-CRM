<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkValue;
use App\Models\ProductOptionLinkValuePricing;
use App\Models\ProductPricing;
use App\Models\ProductUpgradePath;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\UpgradeRequestService;
use App\Services\OrderNumberService;
use App\Settings\ProductSettings;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The WHMCS-style upgrade lifecycle: place → approve (invoice or wallet
 * credit) → apply on payment (or immediately for credits / zero net) →
 * cancel, plus the renewal-cron cancellation of unpaid upgrades.
 */
class UpgradeRequestServiceTest extends TestCase
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

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'company' => 'Upgrade Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(string $name, float $price): Product
    {
        return Product::create([
            'name' => $name,
            'is_bundle' => false,
            'price' => $price,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ]);
    }

    private function price(Product $product, float $price, float $setupFee = 0, string $cycle = 'monthly'): ProductPricing
    {
        return $product->pricing()->create([
            'billing_cycle' => $cycle,
            'price' => $price,
            'setup_fee' => $setupFee,
        ]);
    }

    /**
     * Active order on $from with a served item, deterministic proration:
     * due = today + 30d and period start = today, so a mid-cycle quote always
     * prorates 30 of 30 days (full amounts on both sides).
     */
    private function makeOrder(Product $from, float $unitPrice, array $overrides = []): Order
    {
        $customer = $this->makeCustomer();
        $due = CarbonImmutable::today('Asia/Kolkata')->addDays(30)->toDateString();
        $last = CarbonImmutable::today('Asia/Kolkata')->toDateString();

        $order = Order::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $from->id,
            'order_number' => app(OrderNumberService::class)->next('ORD'),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => $unitPrice,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => $due,
            'last_billing_date' => $last,
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $from->id,
            'product_name' => $from->name,
            'billing_cycle' => $order->billing_cycle,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'total' => $unitPrice,
            'next_billing_date' => $due,
        ]);

        return $order;
    }

    private function enablePath(Product $from, Product $to, string $direction = 'both'): void
    {
        ProductUpgradePath::create([
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'enabled' => true,
            'direction' => $direction,
        ]);
    }

    /**
     * @return array{0: Order, 1: Product, 2: Product}
     */
    private function upgradeScenario(float $fromPrice, float $toPrice, array $orderOverrides = [], string $direction = 'both'): array
    {
        $from = $this->makeProduct('Basic Hosting', $fromPrice);
        $this->price($from, $fromPrice);
        $to = $this->makeProduct('Pro Hosting', $toPrice);
        $this->price($to, $toPrice);
        $this->enablePath($from, $to, $direction);

        return [$this->makeOrder($from, $fromPrice, $orderOverrides), $from, $to];
    }

    private function service(): UpgradeRequestService
    {
        return app(UpgradeRequestService::class);
    }

    /**
     * Attach a customer-editable dropdown option to $product: a free default
     * value and a +$price monthly value. Returns the link; selections are
     * keyed by its id.
     */
    private function attachOption(Product $product, float $price = 200): ProductOptionGroupProduct
    {
        $group = ProductOptionGroup::create([
            'name' => 'Backup Storage',
            'type' => 'dropdown',
            'sort_order' => 1,
        ]);

        $link = ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        $free = ProductOptionLinkValue::create([
            'product_option_group_product_id' => $link->id,
            'label' => 'Backup: Off',
            'is_default' => true,
            'sort_order' => 1,
        ]);
        ProductOptionLinkValuePricing::create([
            'product_option_link_value_id' => $free->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 0,
        ]);

        $paid = ProductOptionLinkValue::create([
            'product_option_group_product_id' => $link->id,
            'label' => 'Backup: 50 GB',
            'is_default' => false,
            'sort_order' => 2,
        ]);
        ProductOptionLinkValuePricing::create([
            'product_option_link_value_id' => $paid->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => $price,
        ]);

        return $link;
    }

    public function test_place_persists_the_quote_snapshot_with_an_upg_number(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);

        $request = $this->service()->place($order, $to, 'Client wants Pro');

        $this->assertMatchesRegularExpression('/^UPG-\d{4}-\d{5}$/', $request->upgrade_no);
        $this->assertSame((int) $order->id, (int) $request->order_id);
        $this->assertSame((int) $order->customer_id, (int) $request->customer_id);
        $this->assertSame((int) $from->id, (int) $request->from_product_id);
        $this->assertSame((int) $to->id, (int) $request->to_product_id);
        $this->assertSame('product', $request->upgrade_type);
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $request->status);
        $this->assertSame('monthly', $request->billing_cycle);
        // 30/30-day proration: full amounts both sides → 100 payable on 100→200.
        $this->assertSame(100.0, (float) $request->credited);
        $this->assertSame(200.0, (float) $request->debited);
        $this->assertSame(0.0, (float) $request->setup_fee);
        $this->assertSame(100.0, (float) $request->payable);
        $this->assertSame(0.0, (float) $request->credit_amount);
        $this->assertSame(30, (int) $request->proration_days);
        $this->assertSame(30, (int) $request->period_days);
        $this->assertSame('Client wants Pro', $request->notes);
    }

    public function test_place_rejects_a_one_time_billing_cycle(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200, ['billing_cycle' => 'one_time']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Upgrades require a recurring billing cycle.');

        $this->service()->place($order, $to);
    }

    public function test_place_rejects_when_upgrades_are_disabled(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_upgrades' => false]);
        $settings->save();

        [$order, $from, $to] = $this->upgradeScenario(100, 200);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Upgrades are disabled.');

        $this->service()->place($order, $to);
    }

    public function test_place_rejects_downgrades_when_disabled(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_downgrades' => false]);
        $settings->save();

        [$order, $from, $to] = $this->upgradeScenario(200, 100);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Downgrades are disabled.');

        $this->service()->place($order, $to);
    }

    public function test_place_rejects_a_non_active_order(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200, ['status' => Order::STATUS_PENDING]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only active services can be upgraded.');

        $this->service()->place($order, $to);
    }

    public function test_place_rejects_an_inactive_target_product(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $to->update(['status' => 'inactive']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The target product is not available.');

        $this->service()->place($order, $to);
    }

    public function test_place_rejects_when_no_enabled_path_exists(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        // Intentionally no ProductUpgradePath row.
        $order = $this->makeOrder($from, 100);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No enabled upgrade path from Basic Hosting to Pro Hosting.');

        $this->service()->place($order, $to);
    }

    public function test_place_rejects_a_second_pending_request_for_the_same_order(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $this->service()->place($order, $to);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Previous upgrade for this service is still pending or unpaid.');

        $this->service()->place($order, $to);
    }

    public function test_approve_with_payable_creates_a_sent_invoice_and_stays_pending(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $request = $this->service()->place($order, $to);

        $approved = $this->service()->approve($request);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertSame(100.0, (float) $invoice->amount);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertStringContainsString("Upgrade {$request->upgrade_no}: Basic Hosting → Pro Hosting", (string) $invoice->notes);
        $this->assertSame(
            CarbonImmutable::today('Asia/Kolkata')->addDays(7)->toDateString(),
            $invoice->due_date->toDateString(),
        );

        $line = $invoice->items->first();
        $this->assertSame((int) $to->id, (int) $line->product_id);
        $this->assertSame(1, (int) $line->quantity);
        $this->assertSame(100.0, (float) $line->unit_price);
        $this->assertSame(100.0, (float) $line->total);
        $this->assertStringContainsString('Upgrade from Basic Hosting to Pro Hosting', (string) $line->description);
        $this->assertStringContainsString('prorated (30 days)', (string) $line->description);

        $fresh = $approved->fresh();
        $this->assertSame((int) $invoice->id, (int) $fresh->invoice_id);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $fresh->status);
    }

    public function test_approve_with_credit_grants_the_wallet_and_applies(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_downgrades' => true]);
        $settings->save();

        [$order, $from, $to] = $this->upgradeScenario(200, 100);
        $request = $this->service()->place($order, $to);

        $approved = $this->service()->approve($request);

        $this->assertDatabaseHas('customer_wallet', [
            'customer_id' => $order->customer_id,
            'type' => 'credit',
            'balance_type' => 'credit',
            'amount' => 100.0,
        ]);

        $wallet = CustomerWallet::where('customer_id', $order->customer_id)->sole();
        $this->assertSame(100.0, (float) $wallet->amount);
        $this->assertStringContainsString('Downgrade credit — '.$request->upgrade_no, (string) $wallet->description);
        $this->assertStringContainsString('(Basic Hosting → Pro Hosting)', (string) $wallet->description);
        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());

        $fresh = $approved->fresh();
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $fresh->status);
        $this->assertNotNull($fresh->applied_at);
        $this->assertNull($fresh->invoice_id);

        $item = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $to->id, (int) $item->product_id);
        $this->assertSame('Pro Hosting', $item->product_name);
        $this->assertSame(100.0, (float) $item->unit_price);
        $this->assertSame(100.0, (float) $item->total);
    }

    public function test_approve_with_zero_net_applies_without_invoice_or_wallet(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 100);
        $request = $this->service()->place($order, $to);

        $approved = $this->service()->approve($request);

        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
        $this->assertSame(0, CustomerWallet::where('customer_id', $order->customer_id)->count());

        $fresh = $approved->fresh();
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $fresh->status);
        $this->assertNull($fresh->invoice_id);

        $item = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $to->id, (int) $item->product_id);
        $this->assertSame(100.0, (float) $item->unit_price);
    }

    public function test_approve_rejects_a_non_pending_request(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $request = $this->service()->place($order, $to);
        $this->service()->apply($request);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Upgrade is not pending.');

        $this->service()->approve($request->fresh());
    }

    public function test_apply_switches_the_served_item_and_preserves_the_due_date(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $request = $this->service()->place($order, $to);
        $dueBefore = $order->next_billing_date;

        $applied = $this->service()->apply($request);

        $item = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $to->id, (int) $item->product_id);
        $this->assertSame('Pro Hosting', $item->product_name);
        $this->assertSame(200.0, (float) $item->unit_price);
        $this->assertSame(200.0, (float) $item->total);
        $this->assertSame($dueBefore->toDateString(), $item->next_billing_date->toDateString());
        $this->assertSame('monthly', $item->billing_cycle);
        $this->assertSame((int) $to->id, (int) $order->fresh()->product_id);

        $fresh = $applied->fresh();
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $fresh->status);
        $this->assertNotNull($fresh->applied_at);

        $this->assertDatabaseHas('activity_log', [
            'customer_id' => $order->customer_id,
            'action' => 'upgrade.applied',
        ]);
        $log = ActivityLog::where('action', 'upgrade.applied')->sole();
        $this->assertStringContainsString($request->upgrade_no, (string) $log->description);
        $this->assertSame((int) $order->id, (int) $log->metadata['order_id']);
        $this->assertSame((int) $request->id, (int) $log->metadata['upgrade_request_id']);
    }

    public function test_apply_is_idempotent_for_an_applied_request(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $request = $this->service()->place($order, $to);
        $this->service()->apply($request);

        $again = $this->service()->apply($request->fresh());

        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $again->status);
        $item = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $to->id, (int) $item->product_id);
        // The re-call returned before doing any work.
        $this->assertSame(1, ActivityLog::where('action', 'upgrade.applied')->count());
    }

    public function test_apply_sets_the_first_due_date_for_a_free_service(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(0, 200, [
            'next_billing_date' => null,
            'last_billing_date' => null,
        ]);
        $request = $this->service()->place($order, $to);
        $this->assertSame(200.0, (float) $request->payable);
        $this->assertSame(0, (int) $request->proration_days);

        $this->service()->apply($request);

        $expected = CarbonImmutable::today('Asia/Kolkata')->addMonth()->toDateString();
        $item = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();
        $this->assertSame($expected, $item->fresh()->next_billing_date->toDateString());
        $this->assertSame($expected, $order->fresh()->next_billing_date->toDateString());
    }

    public function test_cancel_voids_the_unpaid_invoice_and_cancels_the_request(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $request = $this->service()->place($order, $to, 'Client wants Pro');
        $this->service()->approve($request);
        $invoice = Invoice::where('order_id', $order->id)->sole();

        $cancelled = $this->service()->cancel($request->fresh(), 'Customer changed mind');

        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
        $this->assertSame(UpgradeRequest::STATUS_CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertStringContainsString('Customer changed mind', (string) $cancelled->notes);
        $this->assertStringContainsString('Client wants Pro', (string) $cancelled->notes);
    }

    public function test_cancel_rejects_when_the_invoice_has_payments(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $request = $this->service()->place($order, $to);
        $this->service()->approve($request);
        $invoice = Invoice::where('order_id', $order->id)->sole();
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'method' => 'cash',
            'status' => 'completed',
        ]);
        $this->assertSame(Invoice::STATUS_SENT, $invoice->fresh()->status);

        try {
            $this->service()->cancel($request->fresh());
            $this->fail('Cancellation should have been rejected.');
        } catch (DomainException $e) {
            $this->assertSame('Cannot cancel: the upgrade invoice has payments.', $e->getMessage());
        }

        $this->assertSame(UpgradeRequest::STATUS_PENDING, UpgradeRequest::find($request->id)->status);
        $this->assertSame(Invoice::STATUS_SENT, Invoice::find($invoice->id)->status);
    }

    public function test_cancel_rejects_a_non_pending_request(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $request = $this->service()->place($order, $to);
        $this->service()->apply($request);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Upgrade is not pending.');

        $this->service()->cancel($request->fresh());
    }

    public function test_cancel_unpaid_for_order_cancels_only_pending_with_unpaid_invoice(): void
    {
        // A: pending + unpaid invoice → cancelled by the cron hook.
        [$orderA, $fromA, $toA] = $this->upgradeScenario(100, 200);
        $requestA = $this->service()->place($orderA, $toA);
        $this->service()->approve($requestA);
        $invoiceA = Invoice::where('order_id', $orderA->id)->sole();

        // B: pending, awaiting approval (no invoice yet) → left alone.
        [$orderB, $fromB, $toB] = $this->upgradeScenario(100, 200);
        $requestB = $this->service()->place($orderB, $toB);

        // C: already applied (paid) → left alone.
        [$orderC, $fromC, $toC] = $this->upgradeScenario(100, 200);
        $requestC = $this->service()->place($orderC, $toC);
        $this->service()->approve($requestC);
        $this->service()->apply($requestC);

        // D: the referred invoice row is gone → skipped, never throws.
        [$orderD, $fromD, $toD] = $this->upgradeScenario(100, 200);
        $requestD = $this->service()->place($orderD, $toD);
        $this->service()->approve($requestD);
        Invoice::find($requestD->fresh()->invoice_id)->delete();

        $this->assertSame(1, $this->service()->cancelUnpaidForOrder($orderA));
        $this->assertSame(0, $this->service()->cancelUnpaidForOrder($orderB));
        $this->assertSame(0, $this->service()->cancelUnpaidForOrder($orderC));
        $this->assertSame(0, $this->service()->cancelUnpaidForOrder($orderD));

        $this->assertSame(UpgradeRequest::STATUS_CANCELLED, $requestA->fresh()->status);
        $this->assertStringContainsString(
            'Cancelled: renewal invoice generated while the upgrade was unpaid.',
            (string) $requestA->fresh()->notes,
        );
        $this->assertSame(Invoice::STATUS_VOID, $invoiceA->fresh()->status);
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $requestB->fresh()->status);
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $requestC->fresh()->status);
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $requestD->fresh()->status);
    }

    public function test_place_persists_the_target_billing_cycle(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $this->price($to, 2400, 0, 'annual');

        $request = $this->service()->place($order, $to, null, 'annual');

        $this->assertSame('monthly', $request->billing_cycle);
        $this->assertSame('annual', $request->to_billing_cycle);
        $this->assertDatabaseHas('upgrade_requests', [
            'id' => $request->id,
            'to_billing_cycle' => 'annual',
        ]);
        // 30/30-day proration: credited 100, annual debit 2400×30/365 = 197.26.
        $this->assertSame(100.0, (float) $request->credited);
        $this->assertSame(197.26, (float) $request->debited);
        $this->assertSame(97.26, (float) $request->payable);
    }

    public function test_apply_switches_item_and_order_billing_cycle_and_price(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $this->price($to, 2400, 0, 'annual');
        $request = $this->service()->place($order, $to, null, 'annual');
        $dueBefore = $order->next_billing_date;

        $applied = $this->service()->apply($request);

        $item = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $to->id, (int) $item->product_id);
        $this->assertSame('Pro Hosting', $item->product_name);
        $this->assertSame(2400.0, (float) $item->unit_price);
        $this->assertSame(2400.0, (float) $item->total);
        $this->assertSame('annual', $item->billing_cycle);
        // The due date survives the change on the item…
        $this->assertSame($dueBefore->toDateString(), $item->next_billing_date->toDateString());

        $freshOrder = $order->fresh();
        $this->assertSame('annual', $freshOrder->billing_cycle);
        $this->assertSame((int) $to->id, (int) $freshOrder->product_id);
        // …and on the order summary.
        $this->assertSame($dueBefore->toDateString(), $freshOrder->next_billing_date->toDateString());

        $fresh = $applied->fresh();
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $fresh->status);
        $this->assertNotNull($fresh->applied_at);
    }

    public function test_free_to_paid_with_a_cycle_change_sets_due_on_the_new_cycle(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(0, 200, [
            'next_billing_date' => null,
            'last_billing_date' => null,
        ]);
        $this->price($to, 2400, 0, 'annual');
        $request = $this->service()->place($order, $to, null, 'annual');
        $this->assertSame(2400.0, (float) $request->payable);
        $this->assertSame(0, (int) $request->proration_days);

        $this->service()->apply($request);

        $expected = CarbonImmutable::today('Asia/Kolkata')->addMonths(12)->toDateString();
        $item = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();
        $this->assertSame('annual', $item->fresh()->billing_cycle);
        $this->assertSame($expected, $item->fresh()->next_billing_date->toDateString());
        $this->assertSame('annual', $order->fresh()->billing_cycle);
        $this->assertSame($expected, $order->fresh()->next_billing_date->toDateString());
    }

    public function test_cycle_change_reanchors_last_billing_date_so_the_first_renewal_bills(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $this->price($to, 2400, 0, 'annual');
        $request = $this->service()->place($order, $to, null, 'annual');
        $this->service()->approve($request);

        // Paid invoices apply on payment; calling apply() here is the same
        // materialization the InvoicePaid listener performs.
        $this->service()->apply($request);

        $item = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();
        $this->assertSame('annual', $item->fresh()->billing_cycle);
        $this->assertSame($order->next_billing_date->toDateString(), $item->fresh()->next_billing_date->toDateString());

        // The billing-history anchor must move to the start of the first full
        // new cycle (next_due − 12 months). Without it, the once-per-cycle
        // guard (last_billing_date + cycleMonths > today) compares the old
        // monthly bill against the annual cadence and skips the first renewal.
        $expectedAnchor = CarbonImmutable::parse($order->next_billing_date)->subMonths(12)->toDateString();
        $this->assertSame($expectedAnchor, $item->fresh()->last_billing_date->toDateString());

        // The first new-cycle renewal must bill ON the preserved due date.
        app(BillingService::class)->processRecurringBilling(asOf: $order->next_billing_date);

        $renewal = $order->invoices()
            ->where('notes', 'Auto-generated renewal invoice')
            ->latest('id')
            ->first();
        $this->assertNotNull($renewal, 'First annual renewal must be billed on the preserved due date.');
        $this->assertSame(2400.0, (float) $renewal->amount);
    }

    public function test_place_config_options_persists_selections_and_type_when_product_unchanged(): void
    {
        $product = $this->makeProduct('Hosting', 100);
        $this->price($product, 100);
        $this->enablePath($product, $product);
        $link = $this->attachOption($product);
        $paid = $link->linkValues()->where('label', 'Backup: 50 GB')->sole();

        $order = $this->makeOrder($product, 100);
        $request = $this->service()->place($order, $product, 'Add backup', null, [$link->id => $paid->id]);

        $this->assertSame('configoptions', $request->upgrade_type);
        $this->assertSame([(string) $link->id => $paid->id], $request->options);
        // 30/30-day proration: credited 100, debited 100 + 200 = 300.
        $this->assertSame(100.0, (float) $request->credited);
        $this->assertSame(300.0, (float) $request->debited);
        $this->assertSame(200.0, (float) $request->payable);
        $this->assertDatabaseHas('upgrade_requests', [
            'id' => $request->id,
            'upgrade_type' => 'configoptions',
        ]);
    }

    public function test_place_config_options_with_a_product_switch_types_as_product(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $link = $this->attachOption($to);
        $paid = $link->linkValues()->where('label', 'Backup: 50 GB')->sole();

        $request = $this->service()->place($order, $to, null, null, [$link->id => $paid->id]);

        $this->assertSame('product', $request->upgrade_type);
        $this->assertSame([(string) $link->id => $paid->id], $request->options);
        // The options still price the target side: 200 + 200 = 400 vs 100 credited.
        $this->assertSame(400.0, (float) $request->debited);
        $this->assertSame(100.0, (float) $request->credited);
        $this->assertSame(300.0, (float) $request->payable);
    }

    public function test_approve_config_options_invoice_line_notes_the_configuration_change(): void
    {
        $product = $this->makeProduct('Hosting', 100);
        $this->price($product, 100);
        $this->enablePath($product, $product);
        $link = $this->attachOption($product);
        $paid = $link->linkValues()->where('label', 'Backup: 50 GB')->sole();

        $order = $this->makeOrder($product, 100);
        $request = $this->service()->place($order, $product, null, null, [$link->id => $paid->id]);
        $this->service()->approve($request);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $line = $invoice->items->first();
        $this->assertSame(200.0, (float) $line->unit_price);
        $this->assertStringContainsString('Upgrade from Hosting to Hosting', (string) $line->description);
        $this->assertStringEndsWith(' (configuration change)', (string) $line->description);
    }

    public function test_apply_config_options_rewrites_price_and_snapshot_and_bills_renewal(): void
    {
        $product = $this->makeProduct('Hosting', 100);
        $this->price($product, 100);
        $this->enablePath($product, $product);
        $link = $this->attachOption($product);
        $paid = $link->linkValues()->where('label', 'Backup: 50 GB')->sole();

        $order = $this->makeOrder($product, 100);
        $request = $this->service()->place($order, $product, null, null, [$link->id => $paid->id]);
        $this->assertSame('configoptions', $request->upgrade_type);

        // Quantity > 1 proves the total is unit_price × quantity, per-unit math.
        $order->items()->whereNull('product_addon_id')->first()->update(['quantity' => 2, 'total' => 200]);

        $this->service()->apply($request);

        $item = OrderItem::where('order_id', $order->id)->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $product->id, (int) $item->product_id);
        $this->assertSame(300.0, (float) $item->unit_price);
        $this->assertSame(600.0, (float) $item->total);

        // The full snapshot shape replaced the old one, keyed to the target link.
        $snapshot = $item->config_options;
        $this->assertIsArray($snapshot);
        foreach (['product_group_name', 'provisioning_module', 'billing_cycle', 'options'] as $rootKey) {
            $this->assertArrayHasKey($rootKey, $snapshot);
        }
        $this->assertSame('monthly', $snapshot['billing_cycle']);
        $this->assertCount(1, $snapshot['options']);
        $entry = $snapshot['options'][0];
        $this->assertSame((int) $link->id, (int) $entry['id']);
        $this->assertSame((int) $paid->id, (int) $entry['value_id']);
        $this->assertSame(200.0, (float) $entry['price_applied']);

        // The next renewal bills the option-loaded unit price (item had no
        // last_billing_date, so the once-per-cycle guard never skips it).
        app(BillingService::class)->processRecurringBilling(asOf: $order->next_billing_date);

        $renewal = $order->invoices()
            ->where('notes', 'Auto-generated renewal invoice')
            ->latest('id')
            ->first();
        $this->assertNotNull($renewal, 'First renewal after the option change must bill.');
        $this->assertSame(600.0, (float) $renewal->amount);
    }

    public function test_quantity_scaling_reaches_the_invoice(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        // 20/30-day proration: per-unit delta = 133.33 − 66.67 = 66.66.
        $order->update([
            'next_billing_date' => CarbonImmutable::today('Asia/Kolkata')->addDays(20)->toDateString(),
            'last_billing_date' => CarbonImmutable::today('Asia/Kolkata')->subDays(10)->toDateString(),
        ]);
        $order->items()->first()->update(['quantity' => 2]);

        $request = $this->service()->place($order, $to);
        // Aggregate snapshot: delta × 2 = 133.32, not the per-unit 66.66.
        $this->assertEquals(133.32, (float) $request->payable);

        // approve() re-reads the row in its transaction and returns the fresh
        // instance — use IT, not the stale place() object.
        $approved = $this->service()->approve($request);

        $invoice = $approved->invoice()->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(133.32, (float) $invoice->amount);
        // Renewals bill quantity × unit price — the upgrade invoice must not
        // undercharge the second unit.
        $this->assertEquals(2, (int) $order->items()->first()->quantity);
    }

    public function test_place_rejects_a_pair_not_on_the_predefined_direction_list(): void
    {
        // Upgrade pair, but the path is on the downgrade list only.
        [$order, $from, $to] = $this->upgradeScenario(100, 200, [], 'downgrade');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not on the upgrade list');

        $this->service()->place($order, $to);
    }

    public function test_place_rejects_a_downgrade_pair_on_the_upgrade_list_only(): void
    {
        // Downgrade pair (200 → 100), but the path is on the upgrade list only.
        // The global toggle must be on so the direction rule is what rejects.
        app(ProductSettings::class)->fill(['product_enable_downgrades' => true])->save();
        [$order, $from, $to] = $this->upgradeScenario(200, 100, [], 'upgrade');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not on the downgrade list');

        $this->service()->place($order, $to);
    }

    public function test_place_accepts_a_pair_on_the_both_list(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200, [], 'both');

        $request = $this->service()->place($order, $to);

        $this->assertSame(UpgradeRequest::STATUS_PENDING, $request->status);
    }
}
