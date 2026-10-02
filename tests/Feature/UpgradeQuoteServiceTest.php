<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkValue;
use App\Models\ProductOptionLinkValuePricing;
use App\Models\ProductPricing;
use App\Models\User;
use App\Services\Billing\UpgradeQuoteService;
use App\Settings\ProductSettings;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpgradeQuoteServiceTest extends TestCase
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

    private function makeOrder(Product $from, float $unitPrice, array $overrides = []): Order
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'company' => 'Quote Corp',
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

    private function quoteService(): UpgradeQuoteService
    {
        return app(UpgradeQuoteService::class);
    }

    /**
     * Attach a customer-editable dropdown option to $product: a free default
     * value and a priced value (+$price monthly, $annual exact rate when
     * given). Returns the link; selections are keyed by its id.
     */
    private function attachOption(Product $product, float $price = 200, ?float $annual = null): ProductOptionGroupProduct
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

        if ($annual !== null) {
            ProductOptionLinkValuePricing::create([
                'product_option_link_value_id' => $paid->id,
                'billing_cycle' => 'annual',
                'price_modifier' => $annual,
            ]);
        }

        return $link;
    }

    private function changeDate(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-26');
    }

    public function test_mid_cycle_upgrade_prorates_both_sides(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);

        $order = $this->makeOrder($from, 100);

        $quote = $this->quoteService()->quote($order, $to, $this->changeDate());

        // Period 2026-01-16 → 2026-02-15 = 30 days; change 2026-01-26 leaves 20.
        $this->assertTrue($quote['prorated']);
        $this->assertSame(30, $quote['period_days']);
        $this->assertSame(20, $quote['proration_days']);
        $this->assertEquals(66.67, $quote['credited']);
        $this->assertEquals(133.33, $quote['debited']);
        $this->assertEquals(0.0, $quote['setup_fee_difference']);
        // 133.33 − 66.67 = 66.66 (both sides rounded before netting).
        $this->assertEquals(66.66, $quote['total']);
        $this->assertEquals(66.66, $quote['payable']);
        $this->assertEquals(0.0, $quote['credit']);
        $this->assertSame('upgrade', $quote['change_type']);
        $this->assertSame($from->id, $quote['from']['product_id']);
        $this->assertSame('Basic Hosting', $quote['from']['product_name']);
        $this->assertEquals(100.0, $quote['from']['unit_price']);
        $this->assertSame('monthly', $quote['from']['billing_cycle']);
        $this->assertSame($to->id, $quote['to']['product_id']);
        $this->assertSame('Pro Hosting', $quote['to']['product_name']);
        $this->assertEquals(200.0, $quote['to']['price']);
        $this->assertSame('monthly', $quote['to']['billing_cycle']);
        $this->assertEquals(0.0, $quote['to']['setup_fee']);
    }

    public function test_downgrade_prorates_a_credit(): void
    {
        $from = $this->makeProduct('Pro Hosting', 200);
        $this->price($from, 200);
        $to = $this->makeProduct('Basic Hosting', 100);
        $this->price($to, 100);

        $order = $this->makeOrder($from, 200);

        $quote = $this->quoteService()->quote($order, $to, $this->changeDate());

        $this->assertTrue($quote['prorated']);
        $this->assertSame(30, $quote['period_days']);
        $this->assertSame(20, $quote['proration_days']);
        $this->assertEquals(133.33, $quote['credited']);
        $this->assertEquals(66.67, $quote['debited']);
        $this->assertEquals(66.66, $quote['credit']);
        $this->assertEquals(0.0, $quote['payable']);
        $this->assertSame('downgrade', $quote['change_type']);
    }

    public function test_setup_fee_difference_is_added_to_total(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100, 0);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200, 500);

        $order = $this->makeOrder($from, 100);

        $quote = $this->quoteService()->quote($order, $to, $this->changeDate());

        $this->assertEquals(500.0, $quote['setup_fee_difference']);
        // 133.33 − 66.67 + 500 = 566.66
        $this->assertEquals(566.66, $quote['total']);
        $this->assertEquals(566.66, $quote['payable']);
        $this->assertEquals(0.0, $quote['credit']);
        $this->assertEquals(500.0, $quote['to']['setup_fee']);
    }

    public function test_prorated_setting_off_charges_full_amounts(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_prorated_charges' => false]);
        $settings->save();

        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);

        $order = $this->makeOrder($from, 100);

        $quote = $this->quoteService()->quote($order, $to, $this->changeDate());

        $this->assertFalse($quote['prorated']);
        $this->assertEquals(0, $quote['proration_days']);
        $this->assertEquals(100.0, $quote['credited']);
        $this->assertEquals(200.0, $quote['debited']);
        $this->assertEquals(100.0, $quote['payable']);
        $this->assertEquals(0.0, $quote['credit']);
    }

    public function test_free_service_without_next_billing_date_is_not_prorated(): void
    {
        $from = $this->makeProduct('Free Plan', 0);
        $this->price($from, 0);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);

        $order = $this->makeOrder($from, 0, [
            'next_billing_date' => null,
            'last_billing_date' => null,
        ]);

        $quote = $this->quoteService()->quote($order, $to, $this->changeDate());

        $this->assertFalse($quote['prorated']);
        $this->assertSame(0, $quote['period_days']);
        $this->assertSame(0, $quote['proration_days']);
        $this->assertEquals(0.0, $quote['credited']);
        $this->assertEquals(200.0, $quote['debited']);
        $this->assertEquals(200.0, $quote['payable']);
        $this->assertEquals(0.0, $quote['credit']);
    }

    public function test_missing_pricing_for_the_cycle_throws(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('VPS Starter', 300);
        // $to intentionally has no pricing row for the monthly cycle.

        $order = $this->makeOrder($from, 100);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Target product has no pricing for the monthly cycle.');

        $this->quoteService()->quote($order, $to, $this->changeDate());
    }

    public function test_change_type_classifies_upgrade_downgrade_and_equal(): void
    {
        // upgrade
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->assertSame(
            'upgrade',
            $this->quoteService()->quote($this->makeOrder($from, 100), $to, $this->changeDate())['change_type'],
        );

        // downgrade
        $from = $this->makeProduct('Pro Hosting 2', 200);
        $this->price($from, 200);
        $to = $this->makeProduct('Basic Hosting 2', 100);
        $this->price($to, 100);
        $this->assertSame(
            'downgrade',
            $this->quoteService()->quote($this->makeOrder($from, 200), $to, $this->changeDate())['change_type'],
        );

        // equal — same annual price normalizes to the same monthly rate
        $from = $this->makeProduct('Annual A', 1200);
        $this->price($from, 1200, 0, 'annual');
        $to = $this->makeProduct('Annual B', 1200);
        $this->price($to, 1200, 0, 'annual');
        $order = $this->makeOrder($from, 1200, ['billing_cycle' => 'annual']);
        $quote = $this->quoteService()->quote($order, $to, $this->changeDate());

        $this->assertSame('equal', $quote['change_type']);
        $this->assertSame('annual', $quote['to']['billing_cycle']);
    }

    public function test_cycle_change_monthly_to_annual_prorates_both_sides(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->price($to, 2400, 0, 'annual');

        $order = $this->makeOrder($from, 100);

        $quote = $this->quoteService()->quote($order, $to, $this->changeDate(), 'annual');

        // The credit stays on the old 30-day period; the debit spends the same
        // 20 remaining days against a full 365-day annual period.
        $this->assertTrue($quote['prorated']);
        $this->assertSame(30, $quote['period_days']);
        $this->assertSame(365, $quote['to_period_days']);
        $this->assertSame(20, $quote['proration_days']);
        $this->assertTrue($quote['cycle_changed']);
        // 100×20/30 = 66.67 credited; 2400×20/365 = 131.51 debited.
        $this->assertEquals(66.67, $quote['credited']);
        $this->assertEquals(131.51, $quote['debited']);
        $this->assertEquals(0.0, $quote['setup_fee_difference']);
        $this->assertEquals(64.84, $quote['total']);
        $this->assertEquals(64.84, $quote['payable']);
        $this->assertEquals(0.0, $quote['credit']);
        // 2400/yr normalizes to 200/mo vs 100/mo → upgrade.
        $this->assertSame('upgrade', $quote['change_type']);
        $this->assertSame('annual', $quote['to']['billing_cycle']);
    }

    public function test_cycle_change_with_proration_setting_off_charges_full_annual_price(): void
    {
        $settings = app(ProductSettings::class);
        $settings->fill(['product_prorated_charges' => false]);
        $settings->save();

        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->price($to, 2400, 0, 'annual');

        $order = $this->makeOrder($from, 100);

        $quote = $this->quoteService()->quote($order, $to, $this->changeDate(), 'annual');

        $this->assertFalse($quote['prorated']);
        $this->assertSame(0, $quote['proration_days']);
        $this->assertTrue($quote['cycle_changed']);
        $this->assertSame(365, $quote['to_period_days']);
        $this->assertEquals(100.0, $quote['credited']);
        $this->assertEquals(2400.0, $quote['debited']);
        $this->assertEquals(2300.0, $quote['payable']);
        $this->assertEquals(0.0, $quote['credit']);
    }

    public function test_same_cycle_quote_matches_the_legacy_shape(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);

        $order = $this->makeOrder($from, 100);

        $plain = $this->quoteService()->quote($order, $to, $this->changeDate());
        $explicit = $this->quoteService()->quote($order, $to, $this->changeDate(), 'monthly');

        // Explicitly passing the order's own cycle reproduces the legacy
        // numbers and never flags the change.
        $this->assertFalse($explicit['cycle_changed']);
        $this->assertSame(30, $explicit['to_period_days']);
        $this->assertSame($explicit['period_days'], $explicit['to_period_days']);
        $this->assertSame('monthly', $explicit['to']['billing_cycle']);
        $this->assertEquals($plain['credited'], $explicit['credited']);
        $this->assertEquals($plain['debited'], $explicit['debited']);
        $this->assertEquals($plain['payable'], $explicit['payable']);
        $this->assertSame($plain['change_type'], $explicit['change_type']);
    }

    public function test_invalid_target_billing_cycle_throws(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);

        $order = $this->makeOrder($from, 100);

        foreach (['one_time', 'weekly'] as $cycle) {
            try {
                $this->quoteService()->quote($order, $to, $this->changeDate(), $cycle);
                $this->fail("Cycle {$cycle} should have been rejected.");
            } catch (DomainException $e) {
                $this->assertSame("Invalid target billing cycle {$cycle}.", $e->getMessage());
            }
        }
    }

    public function test_change_type_classifies_across_cycles(): void
    {
        // monthly → annual: 2400/yr normalizes to 200/mo vs 100/mo → upgrade.
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200);
        $this->price($to, 2400, 0, 'annual');
        $this->assertSame(
            'upgrade',
            $this->quoteService()->quote($this->makeOrder($from, 100), $to, $this->changeDate(), 'annual')['change_type'],
        );

        // annual → monthly: 100/mo vs 2400/12 = 200/mo → downgrade.
        $annualFrom = $this->makeProduct('Annual Pro', 2400);
        $this->price($annualFrom, 2400, 0, 'annual');
        $monthlyTo = $this->makeProduct('Monthly Basic', 100);
        $this->price($monthlyTo, 100);
        $this->assertSame(
            'downgrade',
            $this->quoteService()->quote(
                $this->makeOrder($annualFrom, 2400, ['billing_cycle' => 'annual', 'last_billing_date' => '2025-02-15']),
                $monthlyTo,
                $this->changeDate(),
                'monthly',
            )['change_type'],
        );
    }

    public function test_config_options_upgrade_quotes_the_prorated_option_delta(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $link = $this->attachOption($from);
        $paid = $link->linkValues()->where('label', 'Backup: 50 GB')->sole();

        // The item unit_price is the bare base — the option was never priced in.
        $order = $this->makeOrder($from, 100);

        $withOptions = $this->quoteService()->quote($order, $from, $this->changeDate(), null, [$link->id => $paid->id]);
        $control = $this->quoteService()->quote($order, $from, $this->changeDate());

        // Null selections reproduce the exact pre-extension numbers.
        $this->assertEquals(66.67, $control['debited']);
        $this->assertEquals(0.0, $control['total']);
        $this->assertEquals(0.0, $control['option_adjustment']);
        $this->assertSame('equal', $control['change_type']);

        // 200/mo option on 20 of 30 remaining days → +133.33 on the debit side.
        $this->assertEquals(200.0, $withOptions['option_adjustment']);
        $this->assertEquals(66.67, $withOptions['credited']);
        $this->assertEquals(200.0, $withOptions['debited']);
        $this->assertEquals(133.33, $withOptions['total']);
        $this->assertEquals(133.33, $withOptions['payable']);
        $this->assertEquals(0.0, $withOptions['credit']);
        $this->assertSame('upgrade', $withOptions['change_type']);
        $this->assertSame(100.0, $withOptions['from']['unit_price']);
        $this->assertSame(100.0, $withOptions['to']['price']);
    }

    public function test_config_options_downgrade_prorates_a_credit(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $link = $this->attachOption($from);
        $free = $link->linkValues()->where('label', 'Backup: Off')->sole();

        // The current configuration already embeds the +200 option in the item.
        $order = $this->makeOrder($from, 300);

        $quote = $this->quoteService()->quote($order, $from, $this->changeDate(), null, [$link->id => $free->id]);

        $this->assertEquals(0.0, $quote['option_adjustment']);
        $this->assertEquals(200.0, $quote['credited']);
        $this->assertEquals(66.67, $quote['debited']);
        $this->assertEquals(133.33, $quote['credit']);
        $this->assertEquals(0.0, $quote['payable']);
        $this->assertSame('downgrade', $quote['change_type']);
    }

    public function test_config_options_with_a_cycle_change_price_the_option_for_the_target_cycle(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $this->price($from, 2400, 0, 'annual');
        $link = $this->attachOption($from, 200, 2500);
        $paid = $link->linkValues()->where('label', 'Backup: 50 GB')->sole();

        $order = $this->makeOrder($from, 100);

        $quote = $this->quoteService()->quote($order, $from, $this->changeDate(), 'annual', [$link->id => $paid->id]);

        $this->assertTrue($quote['cycle_changed']);
        // The option follows the target cycle: exact annual rate, not monthly×1.
        $this->assertEquals(2500.0, $quote['option_adjustment']);
        // (2400 + 2500) × 20/365 = 268.49 debited; 100 × 20/30 = 66.67 credited.
        $this->assertEquals(66.67, $quote['credited']);
        $this->assertEquals(268.49, $quote['debited']);
        $this->assertEquals(201.82, $quote['total']);
        $this->assertEquals(201.82, $quote['payable']);
        $this->assertEquals(0.0, $quote['credit']);
        $this->assertSame('annual', $quote['to']['billing_cycle']);
    }

    public function test_quantity_scales_the_billed_delta_but_not_the_setup_fee(): void
    {
        $from = $this->makeProduct('Basic Hosting', 100);
        $this->price($from, 100);
        $to = $this->makeProduct('Pro Hosting', 200);
        $this->price($to, 200, 500);

        // Qty-1 control: 20/30 days → credited 66.67, debited 133.33,
        // payable 66.66 + flat setup diff 500 = 566.66.
        $single = $this->quoteService()->quote($this->makeOrder($from, 100), $to, $this->changeDate());
        $this->assertEquals(66.67, $single['credited']);
        $this->assertEquals(133.33, $single['debited']);
        $this->assertEquals(566.66, $single['payable']);
        $this->assertSame(1, $single['quantity']);

        // Qty-2: both money sides double (WHMCS prorates the recurring amount
        // = unit × qty); the setup fee difference stays flat (charged once).
        $order = $this->makeOrder($from, 100);
        $order->items()->first()->update(['quantity' => 2]);

        $double = $this->quoteService()->quote($order, $to, $this->changeDate());

        $this->assertSame(2, $double['quantity']);
        $this->assertEquals(133.34, $double['credited']);   // 66.67 × 2
        $this->assertEquals(266.66, $double['debited']);    // 133.33 × 2
        $this->assertEquals(500.0, $double['setup_fee_difference']);
        $this->assertEquals(633.32, $double['payable']);    // 133.32 delta + 500 flat
        $this->assertEquals(0.0, $double['credit']);
        // Per-unit rates remain unscaled for display.
        $this->assertEquals(100.0, $double['from']['unit_price']);
        $this->assertEquals(200.0, $double['to']['price']);
    }
}
