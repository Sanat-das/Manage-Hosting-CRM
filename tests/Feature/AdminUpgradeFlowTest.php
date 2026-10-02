<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkValue;
use App\Models\ProductOptionLinkValuePricing;
use App\Models\ProductPricing;
use App\Models\ProductUpgradePath;
use App\Models\Role;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\UpgradeQuoteService;
use App\Services\Billing\UpgradeRequestService;
use App\Settings\ProductSettings;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Admin upgrade-request console + manual order upgrade/downgrade flow.
 *
 * Setup idiom mirrors UpgradeQuoteServiceTest (product/model helpers) and
 * AdminOrderFlowTest (admin role + permission gates). The admin flow is
 * EXPLICIT approval: approve() on a payable request raises the upgrade
 * invoice and the request stays pending until paid; the manual order flow
 * places AND approves in one step.
 */
class AdminUpgradeFlowTest extends TestCase
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

        // The product_upgrade_paths.direction column is owned by a parallel
        // slice; until its migration lands, add it here so the admin direction
        // filter can be exercised against the frozen contract. A no-op once the
        // real migration is present.
        if (! Schema::hasColumn('product_upgrade_paths', 'direction')) {
            Schema::table('product_upgrade_paths', function ($table) {
                $table->string('direction')->default('both');
            });
        }
    }

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

    private function viewOnlyUser(): User
    {
        $user = User::factory()->create();

        $role = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $permission = Permission::firstOrCreate(
            ['name' => 'product-upgrades.view'],
            ['label' => 'View upgrade requests']
        );
        $role->permissions()->sync([$permission->id]);

        $user->assignRole('viewer');

        return $user;
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

    private function makePath(Product $from, Product $to, string $direction = 'both'): ProductUpgradePath
    {
        $path = ProductUpgradePath::create([
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'enabled' => true,
        ]);

        // direction is owned by a parallel slice (not yet fillable/model-cast);
        // set it directly so this suite exercises the frozen contract.
        $path->forceFill(['direction' => $direction])->save();

        return $path;
    }

    /**
     * Active monthly order with a served item and a live period around today
     * (place() quotes with today, so the period must span the current date).
     */
    private function makeOrder(Product $from, float $unitPrice): Order
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'company' => 'Upgrade Flow Corp',
            'status' => 'active',
        ]);

        $next = CarbonImmutable::today('Asia/Kolkata')->addDays(30)->toDateString();
        $last = CarbonImmutable::today('Asia/Kolkata')->subDays(1)->toDateString();

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $from->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => $unitPrice,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => $next,
            'last_billing_date' => $last,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $from->id,
            'product_name' => $from->name,
            'billing_cycle' => $order->billing_cycle,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'total' => $unitPrice,
            'next_billing_date' => $next,
        ]);

        return $order;
    }

    private function upgradeRequests(): UpgradeRequestService
    {
        return app(UpgradeRequestService::class);
    }

    private function quotes(): UpgradeQuoteService
    {
        return app(UpgradeQuoteService::class);
    }

    /**
     * A customer-editable dropdown option on $product, one value per entry
     * (label + monthly price modifier). Returns the link; the priced value is
     * found via linkValues->firstWhere('label', ...).
     */
    private function addDiscreteOption(Product $product, string $groupName, array $values): ProductOptionGroupProduct
    {
        $group = ProductOptionGroup::create([
            'name' => $groupName,
            'type' => 'dropdown',
            'sort_order' => 1,
        ]);

        $link = ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        foreach ($values as $index => $row) {
            $value = ProductOptionLinkValue::create([
                'product_option_group_product_id' => $link->id,
                'label' => $row['label'],
                'is_default' => (bool) ($row['is_default'] ?? false),
                'sort_order' => $index + 1,
            ]);

            ProductOptionLinkValuePricing::create([
                'product_option_link_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $row['modifier'],
            ]);
        }

        return $link;
    }

    public function test_index_lists_upgrade_requests_and_filters_by_status(): void
    {
        $admin = $this->adminUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $order = $this->makeOrder($from, 100);
        $this->makePath($from, $to);

        $upgrade = $this->upgradeRequests()->place($order, $to);

        $this->actingAs($admin)->get(route('admin.upgrade-requests.index'))
            ->assertOk()
            ->assertSee($upgrade->upgrade_no)
            ->assertSee('Pro Hosting');

        $this->actingAs($admin)->get(route('admin.upgrade-requests.index', ['status' => 'applied']))
            ->assertOk()
            ->assertDontSee($upgrade->upgrade_no);
    }

    public function test_approve_payable_creates_sent_invoice_and_request_stays_pending(): void
    {
        $admin = $this->adminUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $order = $this->makeOrder($from, 100);
        $this->makePath($from, $to);

        $upgrade = $this->upgradeRequests()->place($order, $to);
        $this->assertGreaterThan(0.0, (float) $upgrade->payable);

        $response = $this->actingAs($admin)->post(route('admin.upgrade-requests.approve', $upgrade));

        $response->assertRedirect(route('admin.upgrade-requests.show', $upgrade->fresh()));
        $response->assertSessionHas('success', fn (string $flash) => str_contains($flash, 'approved') && str_contains($flash, 'generated'));

        $fresh = $upgrade->fresh();
        $this->assertNotNull($fresh->invoice_id);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $fresh->status);

        $invoice = $fresh->invoice;
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertEquals((float) $fresh->payable, (float) $invoice->amount);
    }

    public function test_approve_credit_grants_wallet_credit_and_applies_immediately(): void
    {
        $settings = app(ProductSettings::class);
        $settings->product_enable_downgrades = true;
        $settings->save();

        $admin = $this->adminUser();
        $from = $this->makeProduct('Pro Hosting', 200);
        $this->price($from, 200);
        $to = $this->makeProduct('Basic Hosting', 100);
        $this->price($to, 100);
        $order = $this->makeOrder($from, 200);
        $this->makePath($from, $to);

        $upgrade = $this->upgradeRequests()->place($order, $to);
        $this->assertGreaterThan(0.0, (float) $upgrade->credit_amount);

        $response = $this->actingAs($admin)->post(route('admin.upgrade-requests.approve', $upgrade));

        $response->assertRedirect(route('admin.upgrade-requests.show', $upgrade->fresh()));
        $response->assertSessionHas('success', fn (string $flash) => str_contains($flash, "customer's wallet"));

        $this->assertDatabaseHas('customer_wallet', [
            'customer_id' => $order->customer_id,
            'type' => 'credit',
            'balance_type' => 'credit',
        ]);
        $wallet = CustomerWallet::where('customer_id', $order->customer_id)->sole();
        $this->assertEquals((float) $upgrade->fresh()->credit_amount, (float) $wallet->amount);

        // Approval of a credit request applies immediately: request applied,
        // served order item switched to the target product at its price.
        $fresh = $upgrade->fresh();
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $fresh->status);
        $this->assertNotNull($fresh->applied_at);
        $this->assertNull($fresh->invoice_id);

        $this->assertSame($to->id, (int) $order->fresh()->product_id);

        $item = $order->items()->whereNull('product_addon_id')->firstOrFail();
        $this->assertSame($to->id, (int) $item->product_id);
        $this->assertSame($to->name, $item->product_name);
        $this->assertEquals(100.0, (float) $item->unit_price);
    }

    public function test_cancel_voids_the_unpaid_upgrade_invoice(): void
    {
        $admin = $this->adminUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $order = $this->makeOrder($from, 100);
        $this->makePath($from, $to);

        $upgrade = $this->upgradeRequests()->place($order, $to);
        $this->upgradeRequests()->approve($upgrade);
        $this->assertNotNull($upgrade->fresh()->invoice_id);

        $response = $this->actingAs($admin)->post(route('admin.upgrade-requests.cancel', $upgrade));

        $response->assertRedirect(route('admin.upgrade-requests.show', $upgrade->fresh()));
        $response->assertSessionHas('success', fn (string $flash) => str_contains($flash, 'cancelled'));

        $fresh = $upgrade->fresh();
        $this->assertSame(UpgradeRequest::STATUS_CANCELLED, $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame(Invoice::STATUS_VOID, $fresh->invoice->status);
    }

    public function test_cancel_with_invoice_payments_is_rejected(): void
    {
        $admin = $this->adminUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $order = $this->makeOrder($from, 100);
        $this->makePath($from, $to);

        $upgrade = $this->upgradeRequests()->place($order, $to);
        $this->upgradeRequests()->approve($upgrade);
        $invoice = $upgrade->fresh()->invoice;

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 10.00,
            'method' => 'bank_transfer',
            'status' => 'completed',
            'transaction_id' => 'PAY-TEST-'.random_int(1000, 9999),
        ]);
        $this->assertFalse($invoice->fresh()->isFullyPaid());

        $response = $this->actingAs($admin)->post(route('admin.upgrade-requests.cancel', $upgrade));

        $response->assertSessionHasErrors('error');

        $fresh = $upgrade->fresh();
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $fresh->status);
        $this->assertSame(Invoice::STATUS_SENT, $fresh->invoice->status);
    }

    public function test_manual_order_upgrade_places_and_approves_in_one_step(): void
    {
        $admin = $this->adminUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $order = $this->makeOrder($from, 100);
        $this->makePath($from, $to);

        // Step 1 lists the eligible target with its quote.
        $this->actingAs($admin)->get(route('admin.orders.upgrade', $order))
            ->assertOk()
            ->assertSee('Upgrade / Downgrade')
            ->assertSee($to->name);

        $response = $this->actingAs($admin)->post(route('admin.orders.upgrade.store', $order), [
            'to_product_id' => $to->id,
            'notes' => 'Manual move by admin',
            'confirm' => '1',
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('success', fn (string $flash) => str_contains($flash, 'approved'));

        $upgrade = UpgradeRequest::sole();
        $this->assertSame($to->id, $upgrade->to_product_id);
        $this->assertSame('Manual move by admin', $upgrade->notes);
        // Manual approval is explicit: payable > 0 → invoice raised, request
        // still pending until the invoice is paid.
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $upgrade->status);
        $this->assertNotNull($upgrade->invoice_id);
        $this->assertNotNull($upgrade->approved_at);
        $this->assertSame(Invoice::STATUS_SENT, $upgrade->invoice->status);
    }

    public function test_view_only_user_cannot_approve(): void
    {
        $viewer = $this->viewOnlyUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $order = $this->makeOrder($from, 100);
        $this->makePath($from, $to);

        $upgrade = $this->upgradeRequests()->place($order, $to);

        $this->actingAs($viewer)->post(route('admin.upgrade-requests.approve', $upgrade))
            ->assertForbidden();

        $this->assertSame(UpgradeRequest::STATUS_PENDING, $upgrade->fresh()->status);
    }

    public function test_manual_upgrade_form_lists_an_alternate_cycle_and_applies_it(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_downgrades' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        $admin = $this->adminUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        // 600/yr normalizes to 50/mo vs 100/mo → a prorated credit, which
        // approve() grants and applies immediately.
        $this->price($to, 600, 0, 'annual');
        $this->makePath($from, $to);
        $order = $this->makeOrder($from, 100);

        // Step 1 offers the annual cycle as its own pair.
        $this->actingAs($admin)->get(route('admin.orders.upgrade', $order))
            ->assertOk()
            ->assertSee($to->name)
            ->assertSee('Annual');

        $response = $this->actingAs($admin)->post(route('admin.orders.upgrade.store', $order), [
            'to_product_id' => $to->id,
            'to_billing_cycle' => 'annual',
            'notes' => 'Move to annual billing',
            'confirm' => '1',
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('success', fn (string $flash) => str_contains($flash, 'approved'));

        $upgrade = UpgradeRequest::sole();
        $this->assertSame('annual', $upgrade->to_billing_cycle);
        $this->assertSame('monthly', $upgrade->billing_cycle);
        // Credit approval IS the grant: the request applies immediately with
        // the new cycle and the new price (no invoice on the credit path).
        $this->assertSame(UpgradeRequest::STATUS_APPLIED, $upgrade->status);
        $this->assertNull($upgrade->invoice_id);

        $item = $order->items()->whereNull('product_addon_id')->firstOrFail();
        $this->assertSame((int) $to->id, (int) $item->product_id);
        $this->assertSame('Pro Hosting', $item->product_name);
        $this->assertSame('annual', $item->billing_cycle);
        $this->assertEquals(600.0, (float) $item->unit_price);

        $fresh = $order->fresh();
        $this->assertSame('annual', $fresh->billing_cycle);
        $this->assertSame((int) $to->id, (int) $fresh->product_id);
    }

    public function test_manual_order_upgrade_with_options_prices_payable_and_invoice_with_option_delta(): void
    {
        $admin = $this->adminUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $option = $this->addDiscreteOption($to, 'Support Level', [
            ['label' => 'Standard', 'modifier' => 0.0, 'is_default' => true],
            ['label' => 'Priority', 'modifier' => 50.0],
        ]);
        $priority = $option->linkValues->firstWhere('label', 'Priority');
        $order = $this->makeOrder($from, 100);
        $this->makePath($from, $to);

        // Step 1 lists the pair only — the option pickers moved to step 2.
        $this->actingAs($admin)->get(route('admin.orders.upgrade', $order))
            ->assertOk()
            ->assertSee('Upgrade / Downgrade')
            ->assertSee($to->name)
            ->assertDontSee('options['.$option->id.']', false);

        // Step 2 renders the target product's option pickers.
        $this->actingAs($admin)
            ->post(route('admin.orders.upgrade.configure', $order), ['pair' => $to->id.':monthly'])
            ->assertOk()
            ->assertSee('options['.$option->id.']', false)
            ->assertSee('Priority');

        // Step 3 preview prices the chosen option into the payable.
        $this->actingAs($admin)
            ->post(route('admin.orders.upgrade.preview', $order), [
                'to_product_id' => $to->id,
                'to_billing_cycle' => 'monthly',
                'options' => [$option->id => $priority->id],
            ])
            ->assertOk()
            ->assertSee('Option adjustment')
            ->assertSee('₹50.00');

        // The option selection raises the prorated payable: the debit side is
        // priced as base + adjustment, so payable grows by the prorated delta.
        $quoteBase = $this->quotes()->quote($order, $to, null, 'monthly');
        $quoteWithOptions = $this->quotes()->quote($order, $to, null, 'monthly', [$option->id => $priority->id]);
        $this->assertGreaterThan((float) $quoteBase['payable'], (float) $quoteWithOptions['payable']);
        $this->assertEqualsWithDelta(
            (float) $quoteWithOptions['option_adjustment'] * $quoteWithOptions['proration_days'] / $quoteWithOptions['period_days'],
            (float) $quoteWithOptions['payable'] - (float) $quoteBase['payable'],
            0.01,
            'The payable delta is the prorated option adjustment'
        );

        // A product switch stays a product upgrade even with options riding along.
        $response = $this->actingAs($admin)->post(route('admin.orders.upgrade.store', $order), [
            'to_product_id' => $to->id,
            'to_billing_cycle' => 'monthly',
            'notes' => 'Manual move with Priority support',
            'options' => [$option->id => $priority->id],
            'confirm' => '1',
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('success', fn (string $flash) => str_contains($flash, 'approved'));

        $upgrade = UpgradeRequest::sole();
        $this->assertSame('product', $upgrade->upgrade_type);
        $this->assertSame([$option->id => $priority->id], $upgrade->options);
        $this->assertEquals((float) $quoteWithOptions['payable'], (float) $upgrade->payable);

        // The upgrade invoice amount and its line carry the payable that
        // already includes the option delta.
        $invoice = $upgrade->invoice;
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertEquals((float) $upgrade->payable, (float) $invoice->amount);
        $line = $invoice->items->first();
        $this->assertNotNull($line);
        $this->assertEquals((float) $upgrade->payable, (float) $line->total);
    }

    public function test_manual_order_upgrade_with_options_updates_item_price_and_option_snapshot_on_apply(): void
    {
        $admin = $this->adminUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $option = $this->addDiscreteOption($to, 'Support Level', [
            ['label' => 'Standard', 'modifier' => 0.0, 'is_default' => true],
            ['label' => 'Priority', 'modifier' => 50.0],
        ]);
        $priority = $option->linkValues->firstWhere('label', 'Priority');
        $order = $this->makeOrder($from, 100);
        $this->makePath($from, $to);

        $response = $this->actingAs($admin)->post(route('admin.orders.upgrade.store', $order), [
            'to_product_id' => $to->id,
            'notes' => 'Upgrade with Priority support',
            'options' => [$option->id => $priority->id],
            'confirm' => '1',
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('success', fn (string $flash) => str_contains($flash, 'approved'));

        $upgrade = UpgradeRequest::sole();
        $this->assertSame('product', $upgrade->upgrade_type);
        $this->assertGreaterThan(0.0, (float) $upgrade->payable);
        $this->assertNotNull($upgrade->invoice_id);

        // Paying the upgrade invoice materializes the change (InvoicePaid).
        app(BillingService::class)->markPaid((int) $upgrade->invoice_id, (float) $upgrade->invoice->total, 'bank_transfer');

        $item = $order->items()->whereNull('product_addon_id')->firstOrFail();
        $this->assertSame($to->id, (int) $item->product_id);
        $this->assertEquals(250.0, (float) $item->unit_price);

        // The item's config_options snapshot reflects the chosen configuration:
        // the option entry carries the 50.00 adjustment that made 250.
        $snapshot = $item->config_options;
        $entry = collect($snapshot['options'] ?? [])->firstWhere('id', $option->id);
        $this->assertNotNull($entry);
        $this->assertEquals(50.0, (float) $entry['price_applied']);
        $this->assertSame('Priority', $entry['selected']);
    }

    public function test_admin_wizard_runs_plan_configure_review_and_requires_confirmation(): void
    {
        $admin = $this->adminUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $order = $this->makeOrder($from, 100);
        $this->makePath($from, $to);

        // Step 1 (Plan): one radio per pair, posting the "{id}:{cycle}" payload.
        $this->actingAs($admin)->get(route('admin.orders.upgrade', $order))
            ->assertOk()
            ->assertSee('Continue to Configure')
            ->assertSee('value="'.$to->id.':monthly"', false)
            ->assertSee(route('admin.orders.upgrade.configure', $order));

        // Step 2 (Configure): pair summary + Back to Plan, posting to preview.
        $this->actingAs($admin)
            ->post(route('admin.orders.upgrade.configure', $order), ['pair' => $to->id.':monthly'])
            ->assertOk()
            ->assertSee('Plan Summary')
            ->assertSee('Pro Hosting')
            ->assertSee('Back to Plan')
            ->assertSee('Continue to Review');

        // Step 3 (Review & Confirm): full review + required checkbox + banner.
        $this->actingAs($admin)
            ->post(route('admin.orders.upgrade.preview', $order), [
                'to_product_id' => $to->id,
                'to_billing_cycle' => 'monthly',
            ])
            ->assertOk()
            ->assertSee('What you are confirming')
            ->assertSee('This is an upgrade')
            ->assertSee('name="confirm"', false)
            ->assertSee('Edit Configuration');

        // Store WITHOUT the confirmation is rejected and files nothing.
        $this->actingAs($admin)
            ->from(route('admin.orders.upgrade', $order))
            ->post(route('admin.orders.upgrade.store', $order), ['to_product_id' => $to->id])
            ->assertSessionHasErrors(['confirm' => 'You must confirm the upgrade details to proceed.']);
        $this->assertSame(0, UpgradeRequest::count());

        // Store WITH the confirmation places AND approves as before.
        $response = $this->actingAs($admin)->post(route('admin.orders.upgrade.store', $order), [
            'to_product_id' => $to->id,
            'to_billing_cycle' => 'monthly',
            'confirm' => '1',
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $upgrade = UpgradeRequest::sole();
        $this->assertNotNull($upgrade->invoice_id);
        $this->assertNotNull($upgrade->approved_at);
        $this->assertSame($to->id, $upgrade->to_product_id);
    }

    public function test_admin_configure_renders_pickers_for_only_the_chosen_product(): void
    {
        $admin = $this->adminUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $pro = $this->makeProduct('Pro Hosting', 200);
        $this->price($pro, 200);
        $ultra = $this->makeProduct('Ultra Hosting', 300);
        $this->price($ultra, 300);
        $this->makePath($from, $pro);
        $this->makePath($from, $ultra);
        $this->addDiscreteOption($pro, 'Backups', [
            ['label' => 'None', 'modifier' => 0.0, 'is_default' => true],
            ['label' => 'Daily', 'modifier' => 50.0],
        ]);
        $this->addDiscreteOption($ultra, 'Memory', [
            ['label' => '1GB', 'modifier' => 0.0, 'is_default' => true],
            ['label' => '2GB', 'modifier' => 50.0],
        ]);
        $order = $this->makeOrder($from, 100);

        $this->actingAs($admin)
            ->post(route('admin.orders.upgrade.configure', $order), ['pair' => $pro->id.':monthly'])
            ->assertOk()
            ->assertSee('Pro Hosting')
            ->assertSee('Backups')
            // Only the chosen target's options render.
            ->assertDontSee('Memory')
            ->assertSee('Back to Plan');
    }

    public function test_admin_step_one_filters_pairs_by_direction(): void
    {
        // Downgrades enabled globally so direction is the only gate under test.
        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_downgrades' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        $admin = $this->adminUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);

        // Upgrade change on an upgrade-only path → offered.
        $up = $this->makeProduct('Pro Hosting', 200);
        $this->price($up, 200);
        $this->makePath($from, $up, 'upgrade');

        // Upgrade change on a downgrade-only path → NOT offered.
        $upBlocked = $this->makeProduct('Max Hosting', 300);
        $this->price($upBlocked, 300);
        $this->makePath($from, $upBlocked, 'downgrade');

        // Downgrade change on an upgrade-only path → NOT offered.
        $downBlocked = $this->makeProduct('Starter Hosting', 50);
        $this->price($downBlocked, 50);
        $this->makePath($from, $downBlocked, 'upgrade');

        // Downgrade change on a both path → offered.
        $downAllowed = $this->makeProduct('Lite Hosting', 75);
        $this->price($downAllowed, 75);
        $this->makePath($from, $downAllowed, 'both');

        $order = $this->makeOrder($from, 100);

        $this->actingAs($admin)->get(route('admin.orders.upgrade', $order))
            ->assertOk()
            ->assertSee('Pro Hosting')
            ->assertSee('Lite Hosting')
            ->assertDontSee('Max Hosting')
            ->assertDontSee('Starter Hosting');
    }
}
