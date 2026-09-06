<?php

namespace Tests\Feature\Client;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkPricing;
use App\Models\ProductOptionLinkValue;
use App\Models\ProductOptionLinkValuePricing;
use App\Models\ProductOptionPricing;
use App\Models\ProductOptionValue;
use App\Models\ProductPricing;
use App\Models\User;
use App\Services\OrderConfigSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client storefront option selection: per-type controls on the product page,
 * payload validation per option group type, and the price-modifier math
 * (base price + selected value's per-cycle modifier, monthly fallback).
 */
class StoreOptionSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name' => 'Shared Hosting Basic',
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
            'company' => 'Store Corp',
            'status' => 'active',
        ]);
    }

    /**
     * Attach an editable (or informational) option link with values + pricing
     * to the test product.
     *
     * @param  list<array{label: string, modifier: float}>  $valueDefs
     * @return array{0: ProductOptionGroupProduct, 1: list<ProductOptionLinkValue>}
     */
    private function attachLink(string $type, array $valueDefs, bool $editable = true, array $groupAttrs = []): array
    {
        $group = ProductOptionGroup::create(array_merge([
            'name' => 'Configuration',
            'sort_order' => 1,
            'type' => $type,
        ], $groupAttrs));

        $pivot = ProductOptionGroupProduct::create([
            'product_id' => $this->product->id,
            'option_group_id' => $group->id,
            'customer_editable' => $editable,
        ]);

        $linkValues = [];

        foreach ($valueDefs as $sort => $definition) {
            $value = ProductOptionValue::create([
                'option_group_id' => $group->id,
                'label' => $definition['label'],
                'sort_order' => $sort + 1,
            ]);

            ProductOptionPricing::create([
                'option_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $definition['modifier'],
            ]);

            $linkValue = ProductOptionLinkValue::create([
                'product_option_group_product_id' => $pivot->id,
                'label' => $definition['label'],
                'is_default' => $sort === 0,
                'sort_order' => $sort + 1,
            ]);

            ProductOptionLinkValuePricing::create([
                'product_option_link_value_id' => $linkValue->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $definition['modifier'],
            ]);

            $linkValues[] = $linkValue;
        }

        return [$pivot, $linkValues];
    }

    /**
     * Attach an editable continuous (unit-priced) link with per-cycle unit
     * pricing and no discrete link values — the continuous model.
     *
     * @param  array<string, float>  $unitPricing  billing_cycle => unit price
     */
    private function attachUnitPricedLink(string $type, array $unitPricing, bool $editable = true, array $groupAttrs = []): ProductOptionGroupProduct
    {
        $group = ProductOptionGroup::create(array_merge([
            'name' => 'Configuration',
            'sort_order' => 1,
            'type' => $type,
        ], $groupAttrs));

        $pivot = ProductOptionGroupProduct::create([
            'product_id' => $this->product->id,
            'option_group_id' => $group->id,
            'customer_editable' => $editable,
        ]);

        foreach ($unitPricing as $cycle => $price) {
            ProductOptionLinkPricing::create([
                'product_option_group_product_id' => $pivot->id,
                'billing_cycle' => $cycle,
                'price_modifier' => $price,
            ]);
        }

        return $pivot;
    }

    // ─────────────────────── Product page rendering ───────────────────────

    public function test_store_product_page_shows_editable_dropdown_control(): void
    {
        [$link] = $this->attachLink('dropdown', [
            ['label' => 'Standard Support', 'modifier' => 0.0],
            ['label' => 'Priority Support', 'modifier' => 149.0],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->get(route('client.store.show', $this->product))
            ->assertOk()
            ->assertSee('Configuration')
            ->assertSee('Standard Support')
            ->assertSee('Priority Support')
            ->assertSee('Add to Cart')
            ->assertSee('name="options['.$link->id.']"', false);
    }

    // ─────────────────────── Validation + modifier math ───────────────────────

    public function test_add_to_cart_with_valid_selection_applies_price_modifier(): void
    {
        [$link] = $this->attachLink('dropdown', [
            ['label' => 'Standard Support', 'modifier' => 0.0],
            ['label' => 'Priority Support', 'modifier' => 149.0],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 'Priority Support'],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertSame(348.00, $cart[0]['unit_price'], 'base 199.00 + 149.00 modifier');
        $this->assertSame(348.00, $cart[0]['total']);
        $this->assertSame([$link->id => 'Priority Support'], $cart[0]['options']);
    }

    public function test_add_to_cart_requires_selection_for_editable_link(): void
    {
        [$link] = $this->attachLink('dropdown', [
            ['label' => 'Standard Support', 'modifier' => 0.0],
            ['label' => 'Priority Support', 'modifier' => 149.0],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->from(route('client.store.show', $this->product))
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('options.'.$link->id);

        $this->assertNull(session('cart'));
    }

    public function test_add_to_cart_rejects_selection_outside_value_list(): void
    {
        [$link] = $this->attachLink('dropdown', [
            ['label' => 'Standard Support', 'modifier' => 0.0],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->from(route('client.store.show', $this->product))
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 'Enterprise Support'],
            ])
            ->assertSessionHasErrors('options.'.$link->id);

        $this->assertNull(session('cart'));
    }

    public function test_checkbox_multiselect_sums_modifiers(): void
    {
        [$link] = $this->attachLink('checkbox', [
            ['label' => 'Daily backups', 'modifier' => 149.0],
            ['label' => 'Nightly backups', 'modifier' => 99.0],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => ['Daily backups', 'Nightly backups']],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertSame(447.00, $cart[0]['unit_price'], 'base 199.00 + 149.00 + 99.00');
        $this->assertSame(['Daily backups', 'Nightly backups'], $cart[0]['options'][$link->id]);
    }

    public function test_quantity_selection_is_validated_as_positive_integer(): void
    {
        [$link] = $this->attachLink('quantity', [
            ['label' => 'Addon units', 'modifier' => 0.0],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 5],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertSame(5, $cart[0]['options'][$link->id]);

        // Negative quantities are rejected.
        $this->actingAs($customer->user)
            ->from(route('client.store.show', $this->product))
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => -3],
            ])
            ->assertSessionHasErrors('options.'.$link->id);
    }

    public function test_number_selection_is_validated_as_numeric_with_bounds(): void
    {
        [$link] = $this->attachLink('number', [
            ['label' => 'Extra IPs', 'modifier' => 0.0],
        ], groupAttrs: ['input_min' => 1, 'input_max' => 8, 'input_step' => 0.5]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 4],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertSame(4, $cart[0]['options'][$link->id]);

        // Decimal values on the step grid are accepted (step-driven number
        // controls) — a different selection does not merge with the previous.
        $this->actingAs($customer->user)
            ->from(route('client.store.show', $this->product))
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => '4.5'],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertCount(2, $cart);
        $this->assertSame(4.5, (float) $cart[1]['options'][$link->id]);

        // Values above the configured max are rejected.
        $this->actingAs($customer->user)
            ->from(route('client.store.show', $this->product))
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 9],
            ])
            ->assertSessionHasErrors('options.'.$link->id);
    }

    public function test_number_selection_is_validated_against_the_step_grid(): void
    {
        // The shared rules mirror the native step attribute: a crafted payload
        // cannot bypass the browser's step check with an out-of-step value.
        [$link] = $this->attachLink('number', [
            ['label' => 'Storage', 'modifier' => 0.0],
        ], groupAttrs: ['input_min' => 0.5, 'input_max' => 10, 'input_step' => 0.5]);

        $customer = $this->makeCustomerUser();

        // On-grid value (2.5 = 0.5 + 4 × 0.5) is accepted.
        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 2.5],
            ])
            ->assertRedirect(route('client.store.index'));

        // Out-of-step value (2.4 is not on the 0.5 grid) is rejected.
        $this->actingAs($customer->user)
            ->from(route('client.store.show', $this->product))
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 2.4],
            ])
            ->assertSessionHasErrors('options.'.$link->id);
    }

    public function test_quantity_selection_is_whole_units_in_ui_and_server(): void
    {
        // Quantity is a count: the control renders step="1" even when the
        // group declares a decimal step, and the server's integer rule agrees
        // — a crafted decimal payload is rejected.
        [$link] = $this->attachLink('quantity', [
            ['label' => 'Services', 'modifier' => 0.0],
        ], groupAttrs: ['input_min' => 1, 'input_max' => 5, 'input_step' => 0.5]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->get(route('client.store.show', $this->product))
            ->assertOk()
            ->assertSee('id="option-'.$link->id.'"', false)
            // The group declares input_step 0.5, but the quantity control must
            // render step="1" (whole units) — never the group's step.
            ->assertSee('step="1"', false)
            ->assertDontSee('step="0.5"', false);

        // Whole units are accepted; decimals (despite the group's 0.5 step)
        // are rejected — the UI's step="1" and the server agree.
        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 3],
            ])
            ->assertRedirect(route('client.store.index'));

        $this->actingAs($customer->user)
            ->from(route('client.store.show', $this->product))
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => '2.5'],
            ])
            ->assertSessionHasErrors('options.'.$link->id);
    }

    public function test_checkbox_selection_is_capped_by_input_max(): void
    {
        // A checkbox group's input_max caps how many values may be selected
        // (matching the UI grey-out). Without input_max the cap is the value
        // count.
        [$link] = $this->attachLink('checkbox', [
            ['label' => 'Backup', 'modifier' => 0.0],
            ['label' => 'SSL', 'modifier' => 0.0],
            ['label' => 'DDoS', 'modifier' => 0.0],
        ], groupAttrs: ['input_max' => 2]);

        $customer = $this->makeCustomerUser();

        // At the cap (2) → accepted.
        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => ['Backup', 'SSL']],
            ])
            ->assertRedirect(route('client.store.index'));

        // Over the cap (3) → rejected.
        $this->actingAs($customer->user)
            ->from(route('client.store.show', $this->product))
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => ['Backup', 'SSL', 'DDoS']],
            ])
            ->assertSessionHasErrors('options.'.$link->id);
    }

    public function test_text_selection_is_validated_as_string(): void
    {
        [$link] = $this->attachLink('text', [
            ['label' => 'Server name', 'modifier' => 0.0],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 'nyc-web-01'],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertSame('nyc-web-01', $cart[0]['options'][$link->id]);
    }

    public function test_slider_selection_respects_input_bounds(): void
    {
        [$link] = $this->attachLink('slider', [
            ['label' => 'Disk block', 'modifier' => 0.0],
        ], groupAttrs: ['input_min' => 10, 'input_max' => 500, 'input_step' => 10]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 250],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertSame(250, $cart[0]['options'][$link->id]);

        // Out-of-range values are rejected.
        $this->actingAs($customer->user)
            ->from(route('client.store.show', $this->product))
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 999],
            ])
            ->assertSessionHasErrors('options.'.$link->id);
    }

    public function test_add_to_cart_with_slider_selection_multiplies_unit_price(): void
    {
        $link = $this->attachUnitPricedLink('slider', ['monthly' => 100.00], groupAttrs: ['input_min' => 1, 'input_max' => 32, 'input_step' => 1]);

        $customer = $this->makeCustomerUser();

        // The product page embeds the live pricing preview and the unit-price
        // hint for the continuous link.
        $this->actingAs($customer->user)
            ->get(route('client.store.show', $this->product))
            ->assertOk()
            ->assertSee('id="live-price-total"', false)
            ->assertSee('data-unit-price="'.$link->id.'"', false);

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 8],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertSame(999.00, $cart[0]['unit_price'], 'base 199.00 + 8 vCores × 100.00');
        $this->assertSame(999.00, $cart[0]['total'], 'unit price × quantity 1');
        $this->assertSame([$link->id => 8], $cart[0]['options']);
    }

    public function test_add_to_cart_with_quantity_selection_multiplies_unit_price(): void
    {
        $link = $this->attachUnitPricedLink('quantity', ['monthly' => 25.00]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 4],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertSame(299.00, $cart[0]['unit_price'], 'base 199.00 + 4 units × 25.00');
    }

    public function test_continuous_group_without_unit_pricing_leaves_price_at_base(): void
    {
        // Legacy continuous group: link values exist but no unit pricing is
        // configured — the price must stay at the base.
        [$link] = $this->attachLink('quantity', [
            ['label' => 'Addon units', 'modifier' => 0.0],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 5],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertSame(199.00, $cart[0]['unit_price'], 'no unit pricing → base price');
    }

    public function test_informational_link_payload_is_ignored(): void
    {
        // A non-editable link must not be validated nor carried into the cart.
        [$link] = $this->attachLink('dropdown', [
            ['label' => 'cPanel', 'modifier' => 0.0],
            ['label' => 'Plesk Web Admin', 'modifier' => 250.0],
        ], editable: false);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 'Bogus value'],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertSame([], $cart[0]['options'] ?? [], 'Informational selections must be dropped.');
        $this->assertArrayNotHasKey('unit_price', $cart[0], 'No modifier math for informational links.');
    }

    // ─────────────────────── Modifier math (unit level) ───────────────────────

    public function test_format_price_prefers_exact_cycle_then_monthly_fallback(): void
    {
        // Exact cycle match wins.
        $this->assertSame(1698.00, OrderConfigSnapshot::formatPrice(199.0, ['monthly' => 149.0, 'annual' => 1499.0], 'annual'));

        // No exact cycle → monthly modifier applies to any cycle (when monthly
        // is one of the product's enabled cycles).
        $this->assertSame(348.00, OrderConfigSnapshot::formatPrice(199.0, ['monthly' => 149.0, 'annual' => 1499.0], 'quarterly', ['monthly', 'quarterly', 'annual']));

        // The monthly fallback is GATED by the enabled cycles: when monthly is
        // not offered on the product, the fallback must not apply.
        $this->assertSame(199.00, OrderConfigSnapshot::formatPrice(199.0, ['monthly' => 149.0], 'annual', ['annual', 'quarterly']));

        // Empty modifier map adds nothing.
        $this->assertSame(199.00, OrderConfigSnapshot::formatPrice(199.0, [], 'monthly'));

        // Legacy callers (no enabled-cycles argument) keep the un-gated behaviour.
        $this->assertSame(348.00, OrderConfigSnapshot::formatPrice(199.0, ['monthly' => 149.0, 'annual' => 1499.0], 'quarterly'));
    }

    public function test_slider_unit_price_uses_the_selected_enabled_cycle(): void
    {
        // The product offers monthly + annual; the link carries both unit
        // prices, mirroring the enabled cycles.
        $link = $this->attachUnitPricedLink('slider', ['monthly' => 100.00, 'annual' => 1000.00], groupAttrs: ['input_min' => 1, 'input_max' => 32, 'input_step' => 1]);
        ProductPricing::create([
            'product_id' => $this->product->id,
            'billing_cycle' => 'annual',
            'price' => 1990.00,
            'setup_fee' => 0,
        ]);

        $customer = $this->makeCustomerUser();

        // Annual selection × annual unit price — the exact cycle modifier wins.
        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'annual',
                'quantity' => 1,
                'options' => [$link->id => 2],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertSame(3990.00, (float) $cart[0]['unit_price'], 'annual base 1990 + 2 × annual unit price 1000');
    }

    public function test_slider_validation_respects_per_product_override_bounds(): void
    {
        // The group allows 1-32; the product overrides to 2-8.
        $link = $this->attachUnitPricedLink('slider', ['monthly' => 100.00], groupAttrs: ['input_min' => 1, 'input_max' => 32, 'input_step' => 1]);
        $link->update(['input_min' => 2, 'input_max' => 8]);

        $customer = $this->makeCustomerUser();

        // Inside the override range → accepted.
        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 5],
            ])
            ->assertRedirect(route('client.store.index'));

        // Above the override max (but inside the group's 32) → rejected.
        $this->actingAs($customer->user)
            ->from(route('client.store.show', $this->product))
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 10],
            ])
            ->assertSessionHasErrors('options.'.$link->id);
    }

    public function test_free_product_option_selection_is_stored_but_unpriced(): void
    {
        // A free product keeps its option groups (the selection is recorded,
        // it may matter for provisioning) but never charges modifiers.
        $this->product->update(['payment_type' => 'free']);
        ProductPricing::where('product_id', $this->product->id)->update(['price' => 0, 'setup_fee' => 0]);

        [$link] = $this->attachLink('dropdown', [
            ['label' => 'Standard Support', 'modifier' => 0.0],
            ['label' => 'Priority Support', 'modifier' => 149.0],
        ]);

        $customer = $this->makeCustomerUser();

        $this->actingAs($customer->user)
            ->post(route('client.store.cart.add'), [
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'options' => [$link->id => 'Priority Support'],
            ])
            ->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertSame([$link->id => 'Priority Support'], $cart[0]['options'] ?? [], 'The selection is still recorded.');
        $this->assertArrayNotHasKey('unit_price', $cart[0], 'Free products never charge option modifiers (no pinned price).');
    }

    public function test_free_product_offers_no_billing_cycle_selector_on_the_product_page(): void
    {
        // The pricing matrix stores a 'free' marker tier for free products. It
        // is a payment-type marker — never a customer-selectable billing cycle
        // — so the product page must not render "Free — ₹0.00" as a cycle.
        $this->product->update(['payment_type' => 'free', 'billing_cycle' => 'monthly']);
        ProductPricing::query()->delete();
        ProductPricing::create([
            'product_id' => $this->product->id,
            'billing_cycle' => 'free',
            'price' => 0.00,
            'setup_fee' => 0,
        ]);

        $this->actingAs($this->makeCustomerUser()->user)
            ->get(route('client.store.show', $this->product))
            ->assertOk()
            ->assertDontSee('Free — ₹0.00')
            ->assertDontSee('value="free"')
            ->assertSee('Free', false); // the green "Free" price badge is expected

        // Adding the free product submits the product's default cycle — never
        // 'free' — so the billing-cycle validation passes.
        $this->post(route('client.store.cart.add'), [
            'product_id' => $this->product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ])->assertRedirect(route('client.store.index'));

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertSame('monthly', $cart[0]['billing_cycle'], 'The cart never stores the free marker cycle.');
    }

    public function test_stale_free_cycle_cart_entries_are_cleared_automatically(): void
    {
        // Legacy carts can still hold entries whose billing_cycle is 'free' —
        // leftovers from before the storefront stopped offering it. Reading
        // the cart must clear them from the session so they can never be
        // displayed, updated or ordered.
        session()->put('cart', [
            ['product_id' => $this->product->id, 'billing_cycle' => 'free', 'quantity' => 1, 'domain' => null],
            ['product_id' => $this->product->id, 'billing_cycle' => 'monthly', 'quantity' => 1, 'domain' => null],
        ]);

        $this->actingAs($this->makeCustomerUser()->user)
            ->get(route('client.store.cart'))
            ->assertOk()
            ->assertDontSee('Free');

        $cart = session('cart');
        $this->assertCount(1, $cart, 'The stale free entry is removed from the session cart.');
        $this->assertSame('monthly', $cart[0]['billing_cycle'], 'The valid entry is preserved.');
    }
}
