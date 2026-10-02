<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductPricing;
use App\Models\ProductUpgradePath;
use App\Models\Role;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Settings\ProductSettings;
use App\Support\AppSettings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHMCS-parity upgrade journeys walked END TO END through the real stacked
 * slices: client controller → UpgradeRequestService → billing (invoice →
 * payment → InvoicePaid) → ApplyUpgradeOnInvoicePaid → recurring-billing cron.
 *
 * Deterministic proration, pinned with Carbon::setTestNow: period
 * 2026-04-01 → 2026-05-01 (30 days), change on 2026-04-06 → 25 days
 * remaining, both sides rounded before netting — so a 100 → 200 upgrade
 * pays 200×25/30 − 100×25/30 = 166.67 − 83.33 = 83.34 and a 200 → 100
 * downgrade credits 83.34. The due date 2026-05-01 is also the renewal
 * cron's asOf: last_billing_date + 1 month == due, so the once-per-cycle
 * guard lets the due item bill on exactly its due date.
 */
class UpgradeLifecycleE2ETest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin "today" so the quote's proration window is deterministic.
        Carbon::setTestNow(Carbon::parse('2026-04-06'));

        // spatie registers settings classes as container-scoped singletons;
        // flush them so each test resolves a fresh instance for its own DB.
        app()->forgetScopedInstances();

        // AppSettings caches the legacy settings table statically for the
        // request lifetime; reset so each test reads freshly-seeded rows.
        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────── Client journey helpers ───────────────────────────

    private function makeCustomerUser(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Upgrade Lifecycle Corp',
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

    private function makePath(Product $from, Product $to, bool $enabled = true): ProductUpgradePath
    {
        return ProductUpgradePath::create([
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'enabled' => $enabled,
        ]);
    }

    private function makeOrder(Customer $customer, Product $from, float $unitPrice, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $from->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => $unitPrice,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => '2026-05-01',
            'last_billing_date' => '2026-04-01',
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $from->id,
            'product_name' => $from->name,
            'billing_cycle' => $order->billing_cycle,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'total' => $unitPrice,
            'next_billing_date' => $order->next_billing_date?->toDateString(),
        ]);

        return $order;
    }

    /**
     * Full client-facing journey in one call: customer (with user), active
     * monthly order on $from priced at $fromPrice, enabled path to $to priced
     * at $toPrice. Mirrors the ClientUpgradeFlowTest helpers exactly.
     *
     * @return array{customer: Customer, order: Order, from: Product, to: Product}
     */
    private function clientJourney(
        float $fromPrice,
        float $toPrice,
        array $orderOverrides = [],
        string $fromName = 'Basic Hosting',
        string $toName = 'Pro Hosting',
    ): array {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct($fromName, $fromPrice);
        $this->price($from, $fromPrice);
        $to = $this->makeProduct($toName, $toPrice);
        $this->price($to, $toPrice);
        $this->makePath($from, $to);

        return [
            'customer' => $customer,
            'order' => $this->makeOrder($customer, $from, $fromPrice, $orderOverrides),
            'from' => $from,
            'to' => $to,
        ];
    }

    // ─────────────────────────── Admin journey helpers ───────────────────────────

    private function adminUser(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['product-upgrades.view', 'product-upgrades.manage'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    /**
     * Full admin-facing journey in one call: admin user (product-upgrades.*)
     * plus the same active order / product / path setup as the client journey.
     *
     * @return array{admin: User, order: Order, from: Product, to: Product}
     */
    private function adminJourney(float $fromPrice, float $toPrice, array $orderOverrides = []): array
    {
        $from = $this->makeProduct('Basic Hosting', $fromPrice);
        $this->price($from, $fromPrice);
        $to = $this->makeProduct('Pro Hosting', $toPrice);
        $this->price($to, $toPrice);
        $this->makePath($from, $to);

        return [
            'admin' => $this->adminUser(),
            'order' => $this->makeOrder($this->makeCustomerUser(), $from, $fromPrice, $orderOverrides),
            'from' => $from,
            'to' => $to,
        ];
    }

    // ─────────────────────────── Scenarios ───────────────────────────

    public function test_client_upgrade_to_paid_plan_applies_on_invoice_payment(): void
    {
        ['customer' => $customer, 'order' => $order, 'from' => $from, 'to' => $to] = $this->clientJourney(100, 200);

        // Real client POST on the real route; 25/30 proration → 83.34 payable,
        // auto-approved (approval not required) → sent invoice.
        $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1'])
            ->assertRedirect(route('client.orders.show', $order->id));

        $request = UpgradeRequest::sole();
        $this->assertMatchesRegularExpression('/^UPG-\d{4}-\d{5}$/', $request->upgrade_no);
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $request->status);
        $this->assertNotNull($request->approved_at);
        $this->assertNotNull($request->invoice_id);
        $this->assertEquals(83.33, (float) $request->credited);
        $this->assertEquals(166.67, (float) $request->debited);
        $this->assertEquals(83.34, (float) $request->payable);
        $this->assertSame(25, $request->proration_days);
        $this->assertSame(30, $request->period_days);

        $invoice = $request->invoice;
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertSame((int) $order->id, (int) $invoice->order_id);
        $this->assertEquals(83.34, (float) $invoice->amount);

        // Paying the upgrade invoice materializes the upgrade via InvoicePaid.
        app(BillingService::class)->markPaid($invoice->id, (float) $invoice->total, 'bank_transfer');

        $fresh = $request->fresh();
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $fresh->status);
        $this->assertNotNull($fresh->applied_at);

        $item = $order->items()->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $to->id, (int) $item->product_id);
        $this->assertSame('Pro Hosting', $item->product_name);
        $this->assertEquals(200.0, (float) $item->unit_price);
        $this->assertEquals(200.0, (float) $item->total);

        // WHMCS invariants: the due date survives the change on the item and
        // the order, and the order switches its product.
        $this->assertSame((int) $to->id, (int) $order->fresh()->product_id);
        $this->assertSame('2026-05-01', $item->next_billing_date->toDateString());
        $this->assertSame('2026-05-01', $order->fresh()->next_billing_date->toDateString());
        $this->assertSame((int) $from->id, (int) $fresh->from_product_id);
    }

    public function test_renewal_after_applied_upgrade_bills_the_new_price(): void
    {
        ['customer' => $customer, 'order' => $order, 'to' => $to] = $this->clientJourney(100, 200);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1']);

        $request = UpgradeRequest::sole();
        $join = $request->fresh();
        app(BillingService::class)->markPaid((int) $join->invoice_id, (float) $join->invoice->total, 'bank_transfer');
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $request->fresh()->status);

        // The renewal cron on the order's own (unchanged) due date bills the
        // NEW price — not the prorated upgrade invoice amount.
        $result = app(BillingService::class)->processRecurringBilling(
            CarbonImmutable::parse($order->fresh()->next_billing_date->toDateString())
        );

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        $renewal = Invoice::where('order_id', $order->id)
            ->where('notes', 'Auto-generated renewal invoice')
            ->sole();
        $this->assertSame(Invoice::STATUS_SENT, $renewal->status);
        $this->assertEquals(200.0, (float) $renewal->amount);

        $line = $renewal->items->first();
        $this->assertEquals(200.0, (float) $line->unit_price);
        $this->assertSame((int) $to->id, (int) $line->product_id);

        $this->assertNotSame((int) $request->invoice_id, (int) $renewal->id);
        $this->assertNotEquals(83.34, (float) $renewal->amount);

        // The cycle advanced from the item's OWN due date.
        $item = $order->items()->whereNull('product_addon_id')->sole();
        $this->assertSame('2026-06-01', $item->fresh()->next_billing_date->toDateString());
        $this->assertSame('2026-06-01', $order->fresh()->next_billing_date->toDateString());
    }

    public function test_client_downgrade_credit_flow(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_downgrades' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        ['customer' => $customer, 'order' => $order, 'to' => $to] = $this->clientJourney(
            200, 100, [], 'Pro Hosting', 'Basic Hosting',
        );

        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1']);

        // Credit requests never auto-approve: approval IS the grant.
        $request = UpgradeRequest::sole();
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $request->status);
        $this->assertNull($request->invoice_id);
        $this->assertNull($request->approved_at);
        $this->assertEquals(83.34, (float) $request->credit_amount);

        // Admin approval grants the prorated wallet credit and applies.
        $this->actingAs($this->adminUser())
            ->post(route('admin.upgrade-requests.approve', $request))
            ->assertRedirect(route('admin.upgrade-requests.show', $request));

        $this->assertDatabaseHas('customer_wallet', [
            'customer_id' => $customer->id,
            'type' => 'credit',
            'balance_type' => 'credit',
            'amount' => 83.34,
        ]);
        $wallet = CustomerWallet::where('customer_id', $customer->id)->sole();
        $this->assertEquals(83.34, (float) $wallet->amount);

        $fresh = $request->fresh();
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $fresh->status);
        $this->assertNotNull($fresh->applied_at);
        $this->assertNull($fresh->invoice_id);

        $item = $order->items()->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $to->id, (int) $item->product_id);
        $this->assertSame('Basic Hosting', $item->product_name);
        $this->assertEquals(100.0, (float) $item->unit_price);
        $this->assertSame('2026-05-01', $item->next_billing_date->toDateString());
        $this->assertSame('2026-05-01', $order->fresh()->next_billing_date->toDateString());
        $this->assertSame((int) $to->id, (int) $order->fresh()->product_id);
    }

    public function test_unpaid_upgrade_cancelled_by_renewal_cron(): void
    {
        ['customer' => $customer, 'order' => $order, 'from' => $from, 'to' => $to] = $this->clientJourney(100, 200);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1']);

        $request = UpgradeRequest::sole();
        $upgradeInvoice = $request->invoice;
        $this->assertSame(Invoice::STATUS_SENT, $upgradeInvoice->status);

        // The renewal cron bills the OLD configuration and supersedes the
        // unpaid upgrade: request cancelled with the frozen reason, its
        // invoice voided, the served item untouched.
        $result = app(BillingService::class)->processRecurringBilling(
            CarbonImmutable::parse($order->next_billing_date->toDateString())
        );

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        $cancelled = $request->fresh();
        $this->assertSame(UpgradeRequest::STATUS_CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertStringContainsString(
            'renewal invoice generated while the upgrade was unpaid',
            (string) $cancelled->notes,
        );
        $this->assertSame(Invoice::STATUS_VOID, $upgradeInvoice->fresh()->status);

        $renewal = Invoice::where('order_id', $order->id)
            ->where('notes', 'Auto-generated renewal invoice')
            ->sole();
        $this->assertEquals(100.0, (float) $renewal->amount);
        $this->assertEquals(100.0, (float) $renewal->items->first()->unit_price);

        $item = $order->items()->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $from->id, (int) $item->product_id);
        $this->assertEquals(100.0, (float) $item->unit_price);
        $this->assertSame('2026-06-01', $order->fresh()->next_billing_date->toDateString());
    }

    public function test_stacking_guard(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_approval_required' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        ['customer' => $customer, 'order' => $order, 'to' => $to] = $this->clientJourney(100, 200);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1'])
            ->assertSessionHasNoErrors();

        // A second request for the same service is refused on the form, and
        // no second row ever lands.
        $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1'])
            ->assertSessionHasErrors([
                'to_product_id' => 'Previous upgrade for this service is still pending or unpaid.',
            ]);

        $this->assertSame(1, UpgradeRequest::count());
    }

    public function test_admin_manual_upgrade_materializes(): void
    {
        ['admin' => $admin, 'order' => $order, 'to' => $to] = $this->adminJourney(100, 200);

        // Admin entry point: the manual order upgrade places AND approves in
        // one step — invoice now, applied only once the invoice is paid.
        $this->actingAs($admin)
            ->post(route('admin.orders.upgrade.store', $order), [
                'to_product_id' => $to->id,
                'notes' => 'Manual move by admin',
                'confirm' => '1',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $request = UpgradeRequest::sole();
        $this->assertSame('Manual move by admin', $request->notes);
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $request->status);
        $this->assertNotNull($request->approved_at);
        $this->assertNotNull($request->invoice_id);
        $this->assertEquals(83.34, (float) $request->payable);
        $this->assertSame(Invoice::STATUS_SENT, $request->invoice->status);

        app(BillingService::class)->markPaid((int) $request->invoice_id, (float) $request->invoice->total, 'bank_transfer');

        $fresh = $request->fresh();
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $fresh->status);
        $this->assertNotNull($fresh->applied_at);

        $item = $order->items()->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $to->id, (int) $item->product_id);
        $this->assertEquals(200.0, (float) $item->unit_price);
        $this->assertSame('2026-05-01', $item->next_billing_date->toDateString());
        $this->assertSame((int) $to->id, (int) $order->fresh()->product_id);
    }

    public function test_free_to_paid_sets_next_due_date(): void
    {
        // A free service has no billing schedule at all (null dates).
        ['customer' => $customer, 'order' => $order, 'to' => $to] = $this->clientJourney(0, 200, [
            'next_billing_date' => null,
            'last_billing_date' => null,
        ], 'Free Plan');

        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1']);

        // Not prorated: full monthly price is payable, and it auto-approves.
        $request = UpgradeRequest::sole();
        $this->assertEquals(200.0, (float) $request->payable);
        $this->assertSame(0, (int) $request->proration_days);
        $this->assertNotNull($request->approved_at);
        $this->assertNotNull($request->invoice_id);
        $this->assertEquals(200.0, (float) $request->invoice->amount);

        app(BillingService::class)->markPaid((int) $request->invoice_id, (float) $request->invoice->total, 'bank_transfer');

        // WHMCS free→paid rule: the first due date is today + one cycle.
        $expected = '2026-05-06';
        $item = $order->items()->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $to->id, (int) $item->product_id);
        $this->assertEquals(200.0, (float) $item->unit_price);
        $this->assertSame($expected, $item->fresh()->next_billing_date->toDateString());
        $this->assertSame($expected, $order->fresh()->next_billing_date->toDateString());
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $request->fresh()->status);
    }

    public function test_approval_required_setting_holds_the_invoice(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_approval_required' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        ['customer' => $customer, 'order' => $order, 'to' => $to] = $this->clientJourney(100, 200);

        // With approval required the client request waits: pending, no invoice,
        // no approval timestamp.
        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1']);

        $request = UpgradeRequest::sole();
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $request->status);
        $this->assertNull($request->invoice_id);
        $this->assertNull($request->approved_at);

        // Admin approval raises the invoice; the request still waits for money.
        $this->actingAs($this->adminUser())
            ->post(route('admin.upgrade-requests.approve', $request));

        $approved = $request->fresh();
        $this->assertNotNull($approved->invoice_id);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $approved->status);
        $this->assertSame(Invoice::STATUS_SENT, $approved->invoice->status);
        $this->assertEquals(83.34, (float) $approved->invoice->amount);

        app(BillingService::class)->markPaid((int) $approved->invoice_id, (float) $approved->invoice->total, 'bank_transfer');

        $item = $order->items()->whereNull('product_addon_id')->sole();
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $approved->fresh()->status);
        $this->assertSame((int) $to->id, (int) $item->product_id);
        $this->assertEquals(200.0, (float) $item->unit_price);
    }

    public function test_client_monthly_to_annual_upgrade_renews_at_the_annual_price(): void
    {
        // The to product is priced monthly AND annually; last_billing_date is
        // left null so the once-per-cycle renewal guard does not skip the
        // first annual renewal (the stale monthly date would read as "billed
        // within the last 12 months").
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->price($to, 2400, 0, 'annual');
        $this->makePath($from, $to);
        $order = $this->makeOrder($customer, $from, 100, ['last_billing_date' => null]);

        // Real client POST with the WHMCS newproductbillingcycle field; 25/30
        // proration → 83.33 credited, annual debit 2400×25/365 = 164.38.
        $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.store', $order), [
                'to_product_id' => $to->id,
                'to_billing_cycle' => 'annual',
                'confirm' => '1',
            ])
            ->assertRedirect(route('client.orders.show', $order->id));

        $request = UpgradeRequest::sole();
        $this->assertSame('annual', $request->to_billing_cycle);
        $this->assertEquals(83.33, (float) $request->credited);
        $this->assertEquals(164.38, (float) $request->debited);
        $this->assertEquals(81.05, (float) $request->payable);
        $this->assertSame(25, (int) $request->proration_days);

        // Payable → auto-approved invoice; paying it materializes the change.
        $invoice = $request->invoice;
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertEquals(81.05, (float) $invoice->amount);

        app(BillingService::class)->markPaid((int) $invoice->id, (float) $invoice->total, 'bank_transfer');

        $fresh = $request->fresh();
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $fresh->status);
        $this->assertNotNull($fresh->applied_at);

        $item = $order->items()->whereNull('product_addon_id')->sole();
        $this->assertSame((int) $to->id, (int) $item->product_id);
        $this->assertEquals(2400.0, (float) $item->unit_price);
        $this->assertSame('annual', $item->billing_cycle);
        $this->assertSame('annual', $order->fresh()->billing_cycle);

        // WHMCS invariants: the due date survives the change on item and order.
        $this->assertSame('2026-05-01', $item->next_billing_date->toDateString());
        $this->assertSame('2026-05-01', $order->fresh()->next_billing_date->toDateString());

        // The renewal cron on the (unchanged) due date bills the ANNUAL price
        // and advances the item's schedule by 12 months from its own due date.
        $result = app(BillingService::class)->processRecurringBilling(
            CarbonImmutable::parse($order->fresh()->next_billing_date->toDateString())
        );

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(0, $result['errors']);

        $renewal = Invoice::where('order_id', $order->id)
            ->where('notes', 'Auto-generated renewal invoice')
            ->sole();
        $this->assertSame(Invoice::STATUS_SENT, $renewal->status);
        $this->assertEquals(2400.0, (float) $renewal->amount);

        $line = $renewal->items->first();
        $this->assertEquals(2400.0, (float) $line->unit_price);
        $this->assertSame((int) $to->id, (int) $line->product_id);
        $this->assertStringContainsString('Annual', (string) $line->description);

        $item = $item->fresh();
        $this->assertSame('2027-05-01', $item->next_billing_date->toDateString());
        $this->assertSame('2027-05-01', $order->fresh()->next_billing_date->toDateString());
    }

    public function test_change_type_is_persisted_on_upgrade_request(): void
    {
        // Upgrade: 100 → 200
        ['customer' => $customer, 'order' => $order, 'to' => $to] = $this->clientJourney(100, 200);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1']);

        $upgrade = UpgradeRequest::sole();
        $this->assertSame('upgrade', $upgrade->change_type);

        // Downgrade: 200 → 100
        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_downgrades' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        ['customer' => $customer2, 'order' => $order2, 'to' => $to2] = $this->clientJourney(
            200, 100, [], 'Pro Hosting', 'Basic Hosting',
        );

        $this->actingAs($customer2->user)
            ->post(route('client.hosting.upgrade.store', $order2), ['to_product_id' => $to2->id, 'confirm' => '1']);

        $downgrade = UpgradeRequest::where('order_id', $order2->id)->sole();
        $this->assertSame('downgrade', $downgrade->change_type);
    }
}
