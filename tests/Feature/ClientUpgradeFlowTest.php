<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionPricing;
use App\Models\ProductOptionValue;
use App\Models\ProductPricing;
use App\Models\ProductUpgradePath;
use App\Models\Server;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\OrderConfigSnapshot;
use App\Services\ProductOptionLinkService;
use App\Settings\ProductSettings;
use App\Support\AppSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientUpgradeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin "today" so the quote's proration window is deterministic
        // (period 2026-01-16 → 2026-02-15, change on 2026-01-26 → 20/30 days).
        Carbon::setTestNow(Carbon::parse('2026-01-26'));

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

    private function makePath(Product $from, Product $to, bool $enabled = true, string $direction = 'both'): ProductUpgradePath
    {
        return ProductUpgradePath::create([
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'enabled' => $enabled,
            'direction' => $direction,
        ]);
    }

    private function makeCustomerUser(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Upgrade Corp',
            'status' => 'active',
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
            'next_billing_date' => '2026-02-15',
            'last_billing_date' => '2026-01-16',
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
     * A customer-editable dropdown option on the product: labels in display
     * order, each next value priced +100/month over the previous (first value
     * free), snapshot-copied onto the product via ProductOptionLinkService.
     */
    private function makeDropdownOption(Product $product, string $name, array $labels, bool $editable = true): ProductOptionGroupProduct
    {
        $group = ProductOptionGroup::create([
            'name' => $name,
            'type' => 'dropdown',
            'sort_order' => 1,
        ]);

        foreach ($labels as $i => $label) {
            $valueId = ProductOptionValue::create([
                'option_group_id' => $group->id,
                'label' => $label,
                'sort_order' => $i + 1,
            ])->id;

            ProductOptionPricing::create([
                'option_value_id' => $valueId,
                'billing_cycle' => 'monthly',
                'price_modifier' => 100 * $i,
            ]);
        }

        $link = app(ProductOptionLinkService::class)->attachGroup($product, $group);
        $link->update(['customer_editable' => $editable]);

        return $link;
    }

    private function linkValueId(ProductOptionGroupProduct $link, string $label): int
    {
        return (int) $link->linkValues()->where('label', $label)->value('id');
    }

    public function test_routes_resolve(): void
    {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $order = $this->makeOrder($customer, $from, 100);

        $this->assertStringEndsWith(
            '/client/hosting/'.$order->id.'/upgrade',
            route('client.hosting.upgrade', $order),
        );
        $this->assertStringEndsWith(
            '/client/hosting/'.$order->id.'/upgrade',
            route('client.hosting.upgrade.store', $order),
        );
        $this->assertStringEndsWith(
            '/client/hosting/'.$order->id.'/upgrade/configure',
            route('client.hosting.upgrade.configure', $order),
        );
    }

    public function test_eligible_active_order_shows_target_with_payable(): void
    {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->makePath($from, $to);
        $order = $this->makeOrder($customer, $from, 100);

        $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $order))
            ->assertOk()
            ->assertSee('Basic Hosting')
            ->assertSee('Pro Hosting')
            ->assertSee('Monthly')
            // 133.33 debited − 66.67 credited = 66.66 payable.
            ->assertSee('₹66.66')
            // Step 1: one global form straight to the configure step.
            ->assertSee(route('client.hosting.upgrade.configure', $order))
            ->assertSee('Continue to Configure');
    }

    public function test_downgrade_targets_hidden_when_disabled_and_shown_when_enabled(): void
    {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Pro Hosting', 200);
        $this->price($from, 200);
        $to = $this->makeProduct('Basic Hosting', 100);
        $this->price($to, 100);
        $this->makePath($from, $to);
        $order = $this->makeOrder($customer, $from, 200);

        // product_enable_downgrades defaults to off → the downgrade target
        // is hidden and the page falls back to its empty state.
        $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $order))
            ->assertOk()
            ->assertDontSee('Basic Hosting')
            ->assertSee('No upgrades are currently available for this service.');

        // Enabled → the downgrade target appears with its wallet credit.
        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_downgrades' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $order))
            ->assertOk()
            ->assertSee('Basic Hosting')
            // 133.33 credited − 66.67 debited = 66.66 wallet credit.
            ->assertSee('₹66.66')
            ->assertSee('Credit to your wallet');
    }

    public function test_direction_filtering_splits_targets_into_upgrade_and_downgrade_sections(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_downgrades' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Mid Hosting', 150);
        $this->price($from, 150);

        $upgradeOnly = $this->makeProduct('Premium Hosting', 300);
        $this->price($upgradeOnly, 300);
        $downgradeOnly = $this->makeProduct('Starter Hosting', 50);
        $this->price($downgradeOnly, 50);
        $bothUp = $this->makeProduct('Business Hosting', 250);
        $this->price($bothUp, 250);
        $bothDown = $this->makeProduct('Lite Hosting', 75);
        $this->price($bothDown, 75);

        $this->makePath($from, $upgradeOnly, true, 'upgrade');
        $this->makePath($from, $downgradeOnly, true, 'downgrade');
        $this->makePath($from, $bothUp, true, 'both');
        $this->makePath($from, $bothDown, true, 'both');

        $order = $this->makeOrder($customer, $from, 150);

        $content = $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $order))
            ->assertOk()
            ->getContent();

        // Both roles present → two headed subsections.
        $upgradeHeaderPos = strpos($content, 'Upgrade to');
        $downgradeHeaderPos = strpos($content, 'Downgrades to');
        $this->assertNotFalse($upgradeHeaderPos);
        $this->assertNotFalse($downgradeHeaderPos);
        $this->assertLessThan($downgradeHeaderPos, $upgradeHeaderPos);

        // Upgrade-side products sit between the two headers; downgrade-side
        // products sit after the "Downgrades to" header.
        foreach (['Premium Hosting', 'Business Hosting'] as $name) {
            $pos = strpos($content, $name);
            $this->assertNotFalse($pos);
            $this->assertGreaterThan($upgradeHeaderPos, $pos);
            $this->assertLessThan($downgradeHeaderPos, $pos);
        }
        foreach (['Starter Hosting', 'Lite Hosting'] as $name) {
            $pos = strpos($content, $name);
            $this->assertNotFalse($pos);
            $this->assertGreaterThan($downgradeHeaderPos, $pos);
        }
    }

    public function test_direction_mismatch_pairs_are_not_offered(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_downgrades' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Mid Hosting', 150);
        $this->price($from, 150);

        // Direction 'upgrade' but the quote computes as a downgrade (cheaper
        // target) — and the mirror case. Neither may be offered.
        $cheap = $this->makeProduct('Starter Hosting', 50);
        $this->price($cheap, 50);
        $pricey = $this->makeProduct('Premium Hosting', 300);
        $this->price($pricey, 300);

        $this->makePath($from, $cheap, true, 'upgrade');
        $this->makePath($from, $pricey, true, 'downgrade');

        $order = $this->makeOrder($customer, $from, 150);

        $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $order))
            ->assertOk()
            ->assertDontSee('Starter Hosting')
            ->assertDontSee('Premium Hosting')
            ->assertSee('No upgrades are currently available for this service.');
    }

    public function test_downgrade_pairs_hidden_when_toggle_off_even_if_direction_allows(): void
    {
        // product_enable_downgrades defaults to off: a 'both'-direction path to
        // a cheaper product quotes as a downgrade and stays hidden.
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Mid Hosting', 150);
        $this->price($from, 150);
        $cheap = $this->makeProduct('Starter Hosting', 50);
        $this->price($cheap, 50);
        $this->makePath($from, $cheap, true, 'both');

        $order = $this->makeOrder($customer, $from, 150);

        $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $order))
            ->assertOk()
            ->assertDontSee('Starter Hosting')
            ->assertSee('No upgrades are currently available for this service.');

        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_downgrades' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $order))
            ->assertOk()
            ->assertSee('Downgrades to')
            ->assertSee('Starter Hosting');
    }

    public function test_single_role_renders_one_available_plans_list(): void
    {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->makePath($from, $to, true, 'upgrade');
        $order = $this->makeOrder($customer, $from, 100);

        $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $order))
            ->assertOk()
            ->assertSee('Available plans')
            ->assertDontSee('Upgrade to')
            ->assertDontSee('Downgrades to');
    }

    public function test_confirm_page_shows_direction_banner_for_upgrade_and_downgrade(): void
    {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Mid Hosting', 150);
        $this->price($from, 150);
        $pricey = $this->makeProduct('Premium Hosting', 300);
        $this->price($pricey, 300);
        $cheap = $this->makeProduct('Starter Hosting', 50);
        $this->price($cheap, 50);
        $this->makePath($from, $pricey, true, 'upgrade');
        $this->makePath($from, $cheap, true, 'downgrade');
        $order = $this->makeOrder($customer, $from, 150);

        // Upgrade banner: success-tinted, one-time charge wording.
        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.preview', $order), [
                'to_product_id' => $pricey->id,
                'to_billing_cycle' => 'monthly',
            ])
            ->assertOk()
            ->assertSee("You're upgrading to", false)
            ->assertSee('one-time charge of', false)
            ->assertSee('alert-success', false);

        // Downgrade banner: info-tinted, wallet credit wording (toggle on).
        $settings = app(ProductSettings::class);
        $settings->fill(['product_enable_downgrades' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.preview', $order), [
                'to_product_id' => $cheap->id,
                'to_billing_cycle' => 'monthly',
            ])
            ->assertOk()
            ->assertSee("You're downgrading to", false)
            ->assertSee('wallet credit of', false)
            ->assertSee('alert-info', false);
    }

    public function test_post_places_a_pending_request_with_quote_snapshot(): void
    {
        // Approval required → the request stays pending without an invoice.
        $settings = app(ProductSettings::class);
        $settings->fill(['product_approval_required' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->makePath($from, $to);
        $order = $this->makeOrder($customer, $from, 100);

        $response = $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1']);

        $response->assertRedirect(route('client.orders.show', $order->id));

        $request = UpgradeRequest::query()->firstOrFail();

        $this->assertMatchesRegularExpression('/^UPG-\d{4}-\d{5}$/', $request->upgrade_no);
        $this->assertSame($order->id, $request->order_id);
        $this->assertSame($customer->id, $request->customer_id);
        $this->assertSame($from->id, $request->from_product_id);
        $this->assertSame($to->id, $request->to_product_id);
        $this->assertSame('product', $request->upgrade_type);
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $request->status);
        $this->assertSame('monthly', $request->billing_cycle);
        $this->assertEquals(66.67, (float) $request->credited);
        $this->assertEquals(133.33, (float) $request->debited);
        $this->assertEquals(0.0, (float) $request->setup_fee);
        $this->assertEquals(66.66, (float) $request->payable);
        $this->assertEquals(0.0, (float) $request->credit_amount);
        $this->assertSame(20, $request->proration_days);
        $this->assertSame(30, $request->period_days);
        $this->assertNull($request->invoice_id);
        $this->assertNull($request->approved_at);

        $response->assertSessionHas('success', 'Upgrade request '.$request->upgrade_no.' submitted.');
    }

    public function test_store_without_confirmation_is_rejected(): void
    {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->makePath($from, $to);
        $order = $this->makeOrder($customer, $from, 100);

        $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id])
            ->assertSessionHasErrors([
                'confirm' => 'You must confirm the upgrade details to proceed.',
            ]);

        // Nothing is filed without the mandatory confirmation.
        $this->assertSame(0, UpgradeRequest::count());
    }

    public function test_auto_approval_creates_invoice_when_approval_not_required(): void
    {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->makePath($from, $to);
        $order = $this->makeOrder($customer, $from, 100);

        $server = Server::create([
            'name' => 'Server 1',
            'ip_address' => '192.168.1.1',
            'panel_type' => 'custom',
            'status' => 'active',
        ]);

        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $from->id,
            'server_id' => $server->id,
            'order_id' => $order->id,
            'username' => 'upgrader',
            'domain' => 'example.com',
            'status' => 'active',
        ]);

        // Defaults: product_approval_required off → payable upgrades approve
        // immediately, raising the upgrade invoice. Status stays pending.
        $response = $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1']);

        $response->assertRedirect(route('client.hosting.show', $account->id));

        $request = UpgradeRequest::query()->firstOrFail();
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $request->status);
        $this->assertNotNull($request->approved_at);
        $this->assertNotNull($request->invoice_id);

        $invoice = Invoice::query()->findOrFail($request->invoice_id);
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertEquals(66.66, (float) $invoice->amount);
        $this->assertSame($order->id, $invoice->order_id);

        $response->assertSessionHas(
            'success',
            "Upgrade {$request->upgrade_no} approved. Invoice {$invoice->invoice_no} generated.",
        );
    }

    public function test_auto_approval_skipped_when_approval_required(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_approval_required' => true]);
        $settings->save();
        app()->forgetScopedInstances();

        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->makePath($from, $to);
        $order = $this->makeOrder($customer, $from, 100);

        $response = $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1']);

        $request = UpgradeRequest::query()->firstOrFail();

        $response->assertRedirect(route('client.orders.show', $order->id));
        $this->assertSame(UpgradeRequest::STATUS_PENDING, $request->status);
        $this->assertNull($request->invoice_id);
        $this->assertNull($request->approved_at);
        $response->assertSessionHas('success', 'Upgrade request '.$request->upgrade_no.' submitted.');
    }

    public function test_duplicate_pending_request_is_rejected_with_error_on_the_form(): void
    {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->makePath($from, $to);
        $order = $this->makeOrder($customer, $from, 100);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1'])
            ->assertSessionHasNoErrors();

        $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.store', $order), ['to_product_id' => $to->id, 'confirm' => '1'])
            ->assertSessionHasErrors([
                'to_product_id' => 'Previous upgrade for this service is still pending or unpaid.',
            ]);
    }

    public function test_non_owner_gets_404(): void
    {
        $owner = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->makePath($from, $to);
        $order = $this->makeOrder($owner, $from, 100);

        $other = $this->makeCustomerUser();

        $this->actingAs($other->user)
            ->get(route('client.hosting.upgrade', $order))
            ->assertNotFound();
    }

    public function test_ineligible_orders_get_404(): void
    {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->makePath($from, $to);

        // Not active.
        $pending = $this->makeOrder($customer, $from, 100, ['status' => Order::STATUS_PENDING]);
        $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $pending))
            ->assertNotFound();

        // Non-recurring cycle (one_time has no CYCLE_MONTHS entry > 0).
        $oneTime = $this->makeOrder($customer, $from, 100, ['billing_cycle' => 'one_time']);
        $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $oneTime))
            ->assertNotFound();
    }

    public function test_page_lists_an_alternate_cycle_pair_and_post_creates_the_request_with_it(): void
    {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->price($to, 2400, 0, 'annual');
        $this->makePath($from, $to);
        $order = $this->makeOrder($customer, $from, 100);

        // Both the same-cycle pair and the alternate-cycle pair are offered.
        $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $order))
            ->assertOk()
            ->assertSee('Pro Hosting')
            ->assertSee('Annual');

        $response = $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.store', $order), [
                'to_product_id' => $to->id,
                'to_billing_cycle' => 'annual',
                'confirm' => '1',
            ]);

        $response->assertRedirect(route('client.orders.show', $order->id));

        $request = UpgradeRequest::query()->firstOrFail();
        $this->assertSame('monthly', $request->billing_cycle);
        $this->assertSame('annual', $request->to_billing_cycle);
        // Annual pair: 66.67 credited (20/30 of the monthly), debit
        // 2400×20/365 = 131.51, payable 64.84.
        $this->assertEquals(66.67, (float) $request->credited);
        $this->assertEquals(131.51, (float) $request->debited);
        $this->assertEquals(64.84, (float) $request->payable);
        $this->assertSame(20, $request->proration_days);
        $this->assertSame(30, $request->period_days);
    }

    public function test_preview_shows_exact_prorated_payable_for_an_option_change(): void
    {
        // Same product as its own target: a WHMCS configoptions upgrade.
        $customer = $this->makeCustomerUser();
        $product = $this->makeProduct('Basic Hosting', 100);
        $this->price($product, 100);
        $this->makePath($product, $product);
        $link = $this->makeDropdownOption($product, 'Backups', ['None', 'Daily']);
        $order = $this->makeOrder($customer, $product, 100);

        // The served item carries the current configuration: None (+0/mo).
        $order->items->first()->update([
            'config_options' => app(OrderConfigSnapshot::class)->capture(
                $product,
                null,
                [$link->id => $this->linkValueId($link, 'None')],
                'monthly'
            ),
        ]);

        $noneId = $this->linkValueId($link, 'None');
        $dailyId = $this->linkValueId($link, 'Daily');

        // Step 1 lists pairs only — the option pickers moved to step 2.
        $this->actingAs($customer->user)
            ->get(route('client.hosting.upgrade', $order))
            ->assertOk()
            ->assertDontSee('name="options['.$link->id.']"', false);

        // Step 2 (configure) renders the pickers for the chosen pair,
        // preselecting the served value (None) when the product is unchanged.
        $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.configure', $order), [
                'to_product_id' => $product->id,
                'to_billing_cycle' => 'monthly',
            ])
            ->assertOk()
            ->assertSee('name="options['.$link->id.']"', false)
            ->assertSee('value="'.$noneId.'" selected', false)
            ->assertSee('Continue to Review');

        // Pick Daily (+100/mo): debited 200×20/30 = 133.33 − credited
        // 100×20/30 = 66.67 → 66.66 payable.
        $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade.configure', $order))
            ->post(route('client.hosting.upgrade.preview', $order), [
                'to_product_id' => $product->id,
                'to_billing_cycle' => 'monthly',
                'options' => [$link->id => $dailyId],
            ])
            ->assertOk()
            ->assertSee('Basic Hosting')
            ->assertSee('Monthly')
            ->assertSee('Option adjustment')
            ->assertSee('₹100.00')
            ->assertSee('₹66.66')
            ->assertSee('None')
            ->assertSee('Daily')
            // Step 3 renders the review checklist and the required
            // confirmation checkbox, and carries the same hidden option payload.
            ->assertSee('I confirm the details above are correct and I want to proceed.')
            ->assertSee('name="options['.$link->id.']"', false);
    }

    public function test_configure_renders_pickers_for_only_the_chosen_product(): void
    {
        $customer = $this->makeCustomerUser();
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $pro = $this->makeProduct('Pro Hosting', 200);
        $this->price($pro, 200);
        $ultra = $this->makeProduct('Ultra Hosting', 300);
        $this->price($ultra, 300);
        $this->makePath($from, $pro);
        $this->makePath($from, $ultra);
        $this->makeDropdownOption($pro, 'Backups', ['None', 'Daily']);
        $this->makeDropdownOption($ultra, 'Memory', ['1GB', '2GB']);
        $order = $this->makeOrder($customer, $from, 100);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.configure', $order), [
                'to_product_id' => $pro->id,
                'to_billing_cycle' => 'monthly',
            ])
            ->assertOk()
            ->assertSee('Pro Hosting')
            ->assertSee('Backups')
            // Only the chosen target's options render.
            ->assertDontSee('Memory')
            ->assertSee('Back to Plan');
    }

    public function test_configure_repopulates_pickers_from_a_returned_payload(): void
    {
        // The confirm step's "Edit Configuration" form reposts the full
        // payload; step 2 must re-render with the submitted selections applied.
        $customer = $this->makeCustomerUser();
        $product = $this->makeProduct('Basic Hosting', 100);
        $this->price($product, 100);
        $this->makePath($product, $product);
        $link = $this->makeDropdownOption($product, 'Backups', ['None', 'Daily']);
        $order = $this->makeOrder($customer, $product, 100);

        $dailyId = $this->linkValueId($link, 'Daily');

        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.configure', $order), [
                'to_product_id' => $product->id,
                'to_billing_cycle' => 'monthly',
                'options' => [$link->id => $dailyId],
            ])
            ->assertOk()
            ->assertSee('value="'.$dailyId.'" selected', false);
    }

    public function test_confirm_places_configoptions_request_with_options_persisted(): void
    {
        $customer = $this->makeCustomerUser();
        $product = $this->makeProduct('Basic Hosting', 100);
        $this->price($product, 100);
        $this->makePath($product, $product);
        $link = $this->makeDropdownOption($product, 'Backups', ['None', 'Daily']);
        $order = $this->makeOrder($customer, $product, 100);

        $order->items->first()->update([
            'config_options' => app(OrderConfigSnapshot::class)->capture(
                $product,
                null,
                [$link->id => $this->linkValueId($link, 'None')],
                'monthly'
            ),
        ]);

        $dailyId = $this->linkValueId($link, 'Daily');

        $response = $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.store', $order), [
                'to_product_id' => $product->id,
                'to_billing_cycle' => 'monthly',
                'options' => [$link->id => $dailyId],
                'confirm' => '1',
            ]);

        $response->assertRedirect(route('client.orders.show', $order->id));

        $request = UpgradeRequest::query()->firstOrFail();

        // Product unchanged + options → upgrade_type configoptions, selections persisted.
        $this->assertSame('configoptions', $request->upgrade_type);
        $this->assertSame($product->id, $request->to_product_id);
        $this->assertSame([$link->id => $dailyId], $request->options);
        // 133.33 debited − 66.67 credited = 66.66 payable, 20/30 prorated.
        $this->assertEquals(66.67, (float) $request->credited);
        $this->assertEquals(133.33, (float) $request->debited);
        $this->assertEquals(66.66, (float) $request->payable);
        $this->assertSame(20, $request->proration_days);
        $this->assertSame(30, $request->period_days);
    }

    public function test_bad_option_value_still_previews_and_resolver_ignores_unknown(): void
    {
        $customer = $this->makeCustomerUser();
        $product = $this->makeProduct('Basic Hosting', 100);
        $this->price($product, 100);
        $this->makePath($product, $product);
        $link = $this->makeDropdownOption($product, 'Backups', ['None', 'Daily']);
        $order = $this->makeOrder($customer, $product, 100);

        // An unknown value id passes validation (the flow accepts ids) and the
        // resolver simply ignores it — the quote falls back to the base price.
        $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.preview', $order), [
                'to_product_id' => $product->id,
                'to_billing_cycle' => 'monthly',
                'options' => [$link->id => 999999],
            ])
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSee('Basic Hosting')
            ->assertSee('No charge');
    }

    public function test_double_confirm_of_a_configoptions_request_hits_the_pending_error(): void
    {
        $customer = $this->makeCustomerUser();
        $product = $this->makeProduct('Basic Hosting', 100);
        $this->price($product, 100);
        $this->makePath($product, $product);
        $link = $this->makeDropdownOption($product, 'Backups', ['None', 'Daily']);
        $order = $this->makeOrder($customer, $product, 100);

        $payload = [
            'to_product_id' => $product->id,
            'to_billing_cycle' => 'monthly',
            'options' => [$link->id => $this->linkValueId($link, 'Daily')],
            'confirm' => '1',
        ];

        $this->actingAs($customer->user)
            ->post(route('client.hosting.upgrade.store', $order), $payload)
            ->assertSessionHasNoErrors();

        $this->actingAs($customer->user)
            ->from(route('client.hosting.upgrade', $order))
            ->post(route('client.hosting.upgrade.store', $order), $payload)
            ->assertSessionHasErrors([
                'to_product_id' => 'Previous upgrade for this service is still pending or unpaid.',
            ]);
    }
}
