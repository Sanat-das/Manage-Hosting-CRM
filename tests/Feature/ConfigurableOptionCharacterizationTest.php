<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkPricing;
use App\Models\ProductOptionLinkValue;
use App\Models\ProductOptionLinkValuePricing;
use App\Models\ProductPricing;
use App\Models\User;
use App\Services\OrderConfigSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Configurable options - the pricing contract.
 *
 * Configurable options describe a product's features (RAM, CPU, Storage,
 * Backup, Support). They are FIXED on some products (the customer gets one
 * declared value) and CONFIGURABLE on others (the customer picks), they must
 * be visible on the order / invoice / service, and they must be billed on the
 * SAME billing cycle as the product they belong to.
 *
 * These tests were written first, against code that failed all of them, and
 * each docblock below still records the bug it was written to pin - a fixed
 * option charged at zero, an annual order billed one month of an option, a
 * renamed value silently orphaning its price, a snapshot that could not say
 * what it had charged. They now pass, and guard those four from returning.
 *
 * The guards at the bottom passed before the fix too: they pin the boundaries
 * the corrections must not overshoot (an explicitly priced cycle still beats
 * derivation; pre-v2 snapshots still render).
 *
 * Display of the same options is covered by ConfigurableOptionDisplayTest.
 */
class ConfigurableOptionCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name' => 'Cloud VPS Basic',
            'price' => 199.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
            'require_domain' => false,
        ]);

        ProductPricing::create([
            'product_id' => $this->product->id,
            'billing_cycle' => 'monthly',
            'price' => 199.00,
            'setup_fee' => 0,
        ]);
    }

    private function makeCustomerUser(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Option Corp',
            'status' => 'active',
        ]);
    }

    /**
     * Attach a discrete option group (dropdown / radio / checkbox) to the test
     * product with product-scoped link values and their per-cycle modifiers.
     * The first value is the default - for a FIXED link (editable: false) that
     * default is the value the product ships with.
     *
     * @param  list<array{label: string, pricing: array<string, float>}>  $valueDefs
     * @return array{0: ProductOptionGroupProduct, 1: list<ProductOptionLinkValue>}
     */
    private function attachDiscreteLink(string $name, array $valueDefs, bool $editable = true, string $type = 'dropdown'): array
    {
        $group = ProductOptionGroup::create([
            'name' => $name,
            'sort_order' => 1,
            'type' => $type,
        ]);

        $link = ProductOptionGroupProduct::create([
            'product_id' => $this->product->id,
            'option_group_id' => $group->id,
            'customer_editable' => $editable,
        ]);

        $linkValues = [];

        foreach ($valueDefs as $sort => $definition) {
            $linkValue = ProductOptionLinkValue::create([
                'product_option_group_product_id' => $link->id,
                'label' => $definition['label'],
                'is_default' => $sort === 0,
                'sort_order' => $sort + 1,
            ]);

            foreach ($definition['pricing'] as $cycle => $modifier) {
                ProductOptionLinkValuePricing::create([
                    'product_option_link_value_id' => $linkValue->id,
                    'billing_cycle' => $cycle,
                    'price_modifier' => $modifier,
                ]);
            }

            $linkValues[] = $linkValue;
        }

        return [$link, $linkValues];
    }

    /**
     * Attach a continuous option group (slider / number / quantity) priced by
     * a per-cycle unit rate that the customer's value multiplies.
     *
     * @param  array<string, float>  $unitPricing  billing_cycle => unit rate
     */
    private function attachContinuousLink(string $name, string $type, array $unitPricing, array $groupAttrs = []): ProductOptionGroupProduct
    {
        $group = ProductOptionGroup::create(array_merge([
            'name' => $name,
            'sort_order' => 1,
            'type' => $type,
        ], $groupAttrs));

        $link = ProductOptionGroupProduct::create([
            'product_id' => $this->product->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        foreach ($unitPricing as $cycle => $rate) {
            ProductOptionLinkPricing::create([
                'product_option_group_product_id' => $link->id,
                'billing_cycle' => $cycle,
                'price_modifier' => $rate,
            ]);
        }

        return $link;
    }

    /**
     * The cart line the storefront resolved, with a readable failure when the
     * line never pinned a price at all.
     *
     * @return array<string, mixed>
     */
    private function cartLine(int $index = 0): array
    {
        $cart = session('cart');

        $this->assertIsArray($cart, 'The storefront did not put anything in the session cart.');
        $this->assertArrayHasKey($index, $cart, 'Cart line '.$index.' is missing.');
        $this->assertArrayHasKey(
            'unit_price',
            $cart[$index],
            'The cart line pinned no unit_price, so the option prices were never applied to it.'
        );

        return $cart[$index];
    }

    // --------------------------------------------------------------------
    // Target behaviour (currently RED)
    // --------------------------------------------------------------------

    /**
     * A FIXED option must be charged.
     *
     * "8 GB RAM" declared on the product (customer_editable = false, the value
     * flagged is_default) is a feature the customer receives and pays for. Its
     * modifier belongs in the line price exactly like a chosen value's.
     *
     * TODAY: OrderConfigSnapshot::adjustmentsFor() opens with
     * `if (! $link->customer_editable) continue;`, so a fixed option is priced
     * at zero however large its modifier - and addToCart() only pins a price
     * when the customer submitted a selection, so the line stays at the bare
     * base price. Admins who want a fixed feature bundled into the base price
     * set its modifier to 0; that stays expressible after the fix.
     */
    public function test_fixed_option_default_value_is_charged_on_the_line(): void
    {
        $this->attachDiscreteLink('RAM', [
            ['label' => '8 GB', 'pricing' => ['monthly' => 100.00]],
            ['label' => '16 GB', 'pricing' => ['monthly' => 300.00]],
        ], editable: false);

        $customer = $this->makeCustomerUser();

        // Nothing is submitted for a fixed link - the customer cannot change it.
        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
            ])
            ->assertRedirect(route('client.store.index'));

        $line = $this->cartLine();

        $this->assertSame(299.00, (float) $line['unit_price'], 'base 199.00 + fixed 8 GB RAM 100.00');
        $this->assertSame(299.00, (float) $line['total'], 'unit price x quantity 1');
    }

    /**
     * A fixed option is charged once per unit, so it scales with quantity like
     * the rest of the line - two VPSes with 8 GB each cost two lots of RAM.
     */
    public function test_fixed_option_price_scales_with_line_quantity(): void
    {
        $this->attachDiscreteLink('RAM', [
            ['label' => '8 GB', 'pricing' => ['monthly' => 100.00]],
        ], editable: false);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 3,
            ])
            ->assertRedirect(route('client.store.index'));

        $line = $this->cartLine();

        $this->assertSame(299.00, (float) $line['unit_price'], 'base 199.00 + fixed RAM 100.00');
        $this->assertSame(897.00, (float) $line['total'], '299.00 x 3 units');
    }

    /**
     * An option billed on a cycle it has no row for must be DERIVED from the
     * monthly rate, not charged at the bare monthly amount.
     *
     * The option follows the product's billing cycle: a 149.00/mo support
     * upgrade on an annual order is 149.00 x 12 = 1788.00 for the year.
     *
     * TODAY: OrderConfigSnapshot::formatPrice() falls back to the monthly
     * modifier verbatim, so the annual line is 1990 + 149 = 2139.00 - eleven
     * months of the upgrade given away on every annual order, silently.
     */
    public function test_annual_order_derives_the_option_price_from_the_monthly_rate(): void
    {
        ProductPricing::create([
            'product_id' => $this->product->id,
            'billing_cycle' => 'annual',
            'price' => 1990.00,
            'setup_fee' => 0,
        ]);

        [$link, $values] = $this->attachDiscreteLink('Support', [
            ['label' => 'Standard Support', 'pricing' => ['monthly' => 0.00]],
            ['label' => 'Priority Support', 'pricing' => ['monthly' => 149.00]],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'annual',
                'quantity' => 1,
                // By label: the id-keyed payload is a separate concern, pinned
                // by test_selection_is_made_by_value_id_..., so this test fails
                // on the cycle arithmetic alone.
                'options' => [$link->id => $values[1]->label],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('client.store.index'));

        $line = $this->cartLine();

        $this->assertSame(3778.00, (float) $line['unit_price'], 'annual base 1990.00 + 149.00/mo x 12 months');
    }

    /**
     * A one-time cycle must never derive from a recurring rate: multiplying a
     * monthly rate by CYCLE_MONTHS is meaningless for one_time (0 months), so
     * an option with no one_time row adds nothing rather than guessing.
     */
    public function test_one_time_cycle_never_derives_from_the_monthly_rate(): void
    {
        ProductPricing::create([
            'product_id' => $this->product->id,
            'billing_cycle' => 'one_time',
            'price' => 4999.00,
            'setup_fee' => 0,
        ]);

        [$link, $values] = $this->attachDiscreteLink('Support', [
            ['label' => 'Standard Support', 'pricing' => ['monthly' => 0.00]],
            ['label' => 'Priority Support', 'pricing' => ['monthly' => 149.00]],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'one_time',
                'quantity' => 1,
                'options' => [$link->id => $values[1]->label],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('client.store.index'));

        $line = $this->cartLine();

        $this->assertSame(4999.00, (float) $line['unit_price'], 'one_time base only - a monthly rate cannot be annualised into it');
    }

    /**
     * Selections are made by link-value ID, and renaming a value afterwards
     * must not change what anything costs.
     *
     * TODAY: the storefront validates with `Rule::in($link->linkValues->pluck('label'))`
     * and adjustmentsFor() resolves the choice with
     * `firstWhere('label', $label)` - so a value id is rejected outright, and
     * once an admin renames a label the lookup misses, `continue`s, and the
     * option prices at zero with no error anywhere. Two values sharing a label
     * collide the same way.
     */
    public function test_selection_is_made_by_value_id_and_survives_a_label_rename(): void
    {
        [$link, $values] = $this->attachDiscreteLink('Support', [
            ['label' => 'Standard Support', 'pricing' => ['monthly' => 0.00]],
            ['label' => 'Priority Support', 'pricing' => ['monthly' => 149.00]],
        ]);

        $priority = $values[1];
        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->from(route('client.store.show', $this->product))
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => (string) $priority->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('client.store.index'));

        $this->assertSame(348.00, (float) $this->cartLine()['unit_price'], 'base 199.00 + 149.00');

        // The admin renames the value. The customer's choice is unchanged - it
        // was never a string - so the price must not move.
        $priority->update(['label' => 'Priority Support (24x7)']);

        $adjustments = OrderConfigSnapshot::adjustmentsFor(
            $this->product->fresh(),
            [$link->id => (string) $priority->id]
        );

        $this->assertSame(
            149.00,
            (float) ($adjustments['monthly'] ?? 0.0),
            'A renamed label must not orphan the price - the selection is keyed by value id.'
        );
    }

    /**
     * The persisted snapshot must record what the customer got AND what they
     * were charged for it, so the order, the invoice and the service page can
     * all render the configuration from one payload without re-reading the
     * live catalog.
     *
     * Shape (additive - every key the current writer emits is retained):
     *   root:  product_group_name, provisioning_module, billing_cycle, options
     *   entry: id, key, group, unit, type, customer_editable, required,
     *          values, value_id, selected, unit_pricing, price_unit,
     *          price_applied
     *
     * `price_unit` is the rate that was used for the order's cycle (a value's
     * modifier, or the per-unit rate for a continuous option) and
     * `price_applied` is what the option added to the line's unit price. They
     * are named apart from the existing `unit_pricing` map, which stays as-is.
     *
     * TODAY: capture() emits none of key / unit / required / value_id /
     * price_unit / price_applied, takes no cycle at all, and leaves `selected`
     * null on fixed links - which is why the service page falls through to
     * printing every value in the group ("RAM: 8 GB, 16 GB") instead of the
     * one the customer actually has.
     */
    public function test_snapshot_records_the_feature_and_the_price_applied(): void
    {
        [, $ramValues] = $this->attachDiscreteLink('RAM', [
            ['label' => '8 GB', 'pricing' => ['monthly' => 100.00]],
            ['label' => '16 GB', 'pricing' => ['monthly' => 300.00]],
        ], editable: false);

        [$supportLink, $supportValues] = $this->attachDiscreteLink('Support', [
            ['label' => 'Standard Support', 'pricing' => ['monthly' => 0.00]],
            ['label' => 'Priority Support', 'pricing' => ['monthly' => 149.00]],
        ]);

        $snapshot = app(OrderConfigSnapshot::class)->capture(
            $this->product,
            null,
            [$supportLink->id => (string) $supportValues[1]->id],
            'monthly'
        );

        // Root: the legacy keys survive, and the cycle the prices were resolved
        // on is recorded alongside them.
        $this->assertArrayHasKey('product_group_name', $snapshot);
        $this->assertArrayHasKey('provisioning_module', $snapshot);
        $this->assertArrayHasKey('options', $snapshot);
        $this->assertArrayHasKey('billing_cycle', $snapshot, 'The snapshot must record the cycle its prices were resolved on.');
        $this->assertSame('monthly', $snapshot['billing_cycle']);

        $byGroup = collect($snapshot['options'])->keyBy('group');
        $this->assertTrue($byGroup->has('RAM'), 'Fixed options belong in the snapshot too.');
        $this->assertTrue($byGroup->has('Support'));

        // Every legacy entry key is still emitted - old readers keep working.
        foreach (['id', 'group', 'type', 'customer_editable', 'values', 'selected', 'unit_pricing'] as $legacyKey) {
            $this->assertArrayHasKey($legacyKey, $byGroup['RAM'], "Legacy snapshot key '{$legacyKey}' was dropped.");
        }

        $ram = $byGroup['RAM'];

        $this->assertSame('ram', $ram['key'] ?? null, 'Machine-readable key, backfilled from the group name, for provisioning and display.');
        $this->assertArrayHasKey('unit', $ram, 'Unit of measure (GB / vCPU) - null until an admin sets one.');
        $this->assertFalse($ram['customer_editable']);
        $this->assertSame('8 GB', $ram['selected'] ?? null, 'A fixed option resolves to its default value, not to null.');
        $this->assertSame($ramValues[0]->id, $ram['value_id'] ?? null);
        $this->assertSame(100.00, (float) ($ram['price_unit'] ?? 0), 'The 8 GB rate for this cycle.');
        $this->assertSame(100.00, (float) ($ram['price_applied'] ?? 0), 'What the fixed option added to the line.');

        $support = $byGroup['Support'];

        $this->assertSame('support', $support['key'] ?? null);
        $this->assertTrue($support['customer_editable']);
        $this->assertTrue($support['required'] ?? null, 'Links are required by default; optional add-ons opt out per link.');
        $this->assertSame('Priority Support', $support['selected'] ?? null, 'selected stays the human label - the id lives in value_id.');
        $this->assertSame($supportValues[1]->id, $support['value_id'] ?? null);
        $this->assertSame(149.00, (float) ($support['price_applied'] ?? 0));
    }

    /**
     * A continuous option records its per-unit rate and the resulting amount,
     * so "Storage: 200 (GB)" on an invoice can be explained as 200 x 2.50.
     */
    public function test_snapshot_records_the_unit_rate_for_continuous_options(): void
    {
        $link = $this->attachContinuousLink('Storage', 'slider', ['monthly' => 2.50], [
            'input_min' => 10,
            'input_max' => 500,
            'input_step' => 10,
        ]);

        $snapshot = app(OrderConfigSnapshot::class)->capture(
            $this->product,
            null,
            [$link->id => 200],
            'monthly'
        );

        $storage = collect($snapshot['options'])->firstWhere('group', 'Storage');

        $this->assertNotNull($storage);
        $this->assertSame('storage', $storage['key'] ?? null);
        $this->assertSame(200, $storage['selected'] ?? null);
        $this->assertSame(2.50, (float) ($storage['price_unit'] ?? 0), 'Per-unit rate for the cycle.');
        $this->assertSame(500.00, (float) ($storage['price_applied'] ?? 0), '200 units x 2.50');
    }

    // --------------------------------------------------------------------
    // Guards - green today, and must stay green after the fix
    // --------------------------------------------------------------------

    /**
     * The cycle derivation must not override an explicitly priced cycle: when
     * the admin has entered an annual rate for the option, that rate is used
     * verbatim rather than monthly x 12.
     *
     * Submitted by LABEL on purpose: the id-keyed payload of
     * test_selection_is_made_by_value_id_and_survives_a_label_rename() becomes
     * the authority, but labels stay accepted as a fallback through the
     * transition so a page a customer already had open cannot start 422-ing
     * mid-release. This guard pins that fallback.
     */
    public function test_an_explicit_cycle_rate_still_wins_over_derivation(): void
    {
        ProductPricing::create([
            'product_id' => $this->product->id,
            'billing_cycle' => 'annual',
            'price' => 1990.00,
            'setup_fee' => 0,
        ]);

        [$link, $values] = $this->attachDiscreteLink('Support', [
            ['label' => 'Standard Support', 'pricing' => ['monthly' => 0.00]],
            ['label' => 'Priority Support', 'pricing' => ['monthly' => 149.00, 'annual' => 1499.00]],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'annual',
                'quantity' => 1,
                'options' => [$link->id => 'Priority Support'],
            ])
            ->assertRedirect(route('client.store.index'));

        $this->assertSame(
            3489.00,
            (float) $this->cartLine()['unit_price'],
            'annual base 1990.00 + the explicit annual rate 1499.00 (never 149 x 12)'
        );
    }

    /**
     * Snapshots written before the shape change carry none of the new keys.
     * The shared renderer must keep displaying them, so historical orders,
     * invoices and services do not go blank when the writer changes.
     */
    public function test_legacy_snapshot_entries_still_render(): void
    {
        $legacyEntry = [
            'id' => 41,
            'group' => 'Support',
            'type' => 'dropdown',
            'customer_editable' => true,
            'values' => ['Standard Support', 'Priority Support'],
            'selected' => 'Priority Support',
        ];

        $html = view('partials._selected_options', [
            'entries' => [$legacyEntry],
            'modifiersByLink' => [],
            'cycle' => 'monthly',
            'includeUnselected' => true,
        ])->render();

        $this->assertStringContainsString('Support', $html);
        $this->assertStringContainsString('Priority Support', $html);
    }

    /**
     * The storefront must not price an option group that carries no rate for
     * the cycle at all - a legacy group with no pricing rows leaves the line
     * at the base price rather than erroring or guessing.
     */
    public function test_option_without_any_pricing_leaves_the_line_at_base(): void
    {
        [$link] = $this->attachDiscreteLink('Panel', [
            ['label' => 'cPanel', 'pricing' => []],
            ['label' => 'Plesk', 'pricing' => []],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 'Plesk'],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');

        $this->assertSame(199.00, (float) ($cart[0]['unit_price'] ?? 199.00), 'No rate anywhere -> base price.');
    }
}
