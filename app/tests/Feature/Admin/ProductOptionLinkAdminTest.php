<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkPricing;
use App\Models\ProductOptionLinkValue;
use App\Models\ProductOptionLinkValuePricing;
use App\Models\ProductOptionPricing;
use App\Models\ProductOptionValue;
use App\Models\ProductPricing;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin product option link management (attach / bulk-update / detach of the
 * product-scoped option snapshots: product_option_link_values +
 * product_option_link_value_pricing).
 */
class ProductOptionLinkAdminTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['products.view', 'products.create', 'products.edit'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeProduct(string $name = 'Shared Hosting Basic'): Product
    {
        return Product::create([
            'name' => $name,
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'active',
        ]);
    }

    private function makeGroup(string $name = 'Control Panel', string $type = 'dropdown'): ProductOptionGroup
    {
        $group = ProductOptionGroup::create([
            'name' => $name,
            'sort_order' => 1,
            'type' => $type,
        ]);

        $definitions = [
            ['label' => 'cPanel', 'modifier' => 0.0],
            ['label' => 'Plesk Web Admin', 'modifier' => 250.0],
        ];

        foreach ($definitions as $sort => $definition) {
            $valueId = ProductOptionValue::create([
                'option_group_id' => $group->id,
                'label' => $definition['label'],
                'sort_order' => $sort + 1,
            ])->id;

            ProductOptionPricing::create([
                'option_value_id' => $valueId,
                'billing_cycle' => 'monthly',
                'price_modifier' => $definition['modifier'],
            ]);
        }

        return $group;
    }

    /**
     * A continuous (unit-priced) option group with no catalog values.
     */
    private function makeContinuousGroup(string $name = 'CPU', string $type = 'slider'): ProductOptionGroup
    {
        return ProductOptionGroup::create([
            'name' => $name,
            'sort_order' => 1,
            'type' => $type,
            'input_min' => 1,
            'input_max' => 32,
            'input_step' => 1,
        ]);
    }

    /**
     * Attach the group to the product through the admin route and return the
     * created pivot row.
     */
    private function attach(Product $product, ProductOptionGroup $group, bool $editable = false): ProductOptionGroupProduct
    {
        $this->actingAsAdmin()
            ->from(route('admin.products.edit', $product))
            ->post(route('admin.products.options.attach', $product), [
                'option_group_id' => $group->id,
                'customer_editable' => $editable ? '1' : '0',
            ])
            ->assertSessionHas('success');

        $link = ProductOptionGroupProduct::query()
            ->where('product_id', $product->id)
            ->where('option_group_id', $group->id)
            ->first();

        $this->assertNotNull($link, 'Attach must create the pivot row.');

        return $link;
    }

    /**
     * Save a per-link option payload through the single product update form —
     * the per-link PUT route no longer exists.
     */
    private function updateLinkThroughProduct(Product $product, ProductOptionGroupProduct $link, array $payload): TestResponse
    {
        return $this->actingAsAdmin()
            ->from(route('admin.products.edit', $product))
            ->put(route('admin.products.update', $product), [
                'name' => $product->name,
                'billing_cycle' => 'monthly',
                'provisioning_module' => 'manual',
                'status' => 'active',
                'gst_type' => 'standard',
                'option_links' => [$link->id => $payload],
            ]);
    }

    // ─────────────────────────────── Attach ───────────────────────────────

    public function test_attach_copies_group_values_and_pricing_into_link_tables(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();
        $group = $this->makeGroup();

        $this->from(route('admin.products.edit', $product))
            ->post(route('admin.products.options.attach', $product), [
                'option_group_id' => $group->id,
                'customer_editable' => '1',
            ])
            ->assertSessionHas('success');

        $link = ProductOptionGroupProduct::query()
            ->where('product_id', $product->id)
            ->where('option_group_id', $group->id)
            ->sole();

        // Pivot carries the customer-editable switch value.
        $this->assertTrue($link->customer_editable);

        // The group's values are copied verbatim, first becomes default.
        $values = $link->linkValues()->orderBy('sort_order')->get();
        $this->assertCount(2, $values);
        $this->assertSame('cPanel', $values[0]->label);
        $this->assertTrue($values[0]->is_default);
        $this->assertSame('Plesk Web Admin', $values[1]->label);
        $this->assertFalse($values[1]->is_default);

        // Per-value pricing is copied into product_option_link_value_pricing.
        $this->assertDatabaseHas('product_option_link_value_pricing', [
            'product_option_link_value_id' => $values[0]->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 0.00,
        ]);
        $this->assertDatabaseHas('product_option_link_value_pricing', [
            'product_option_link_value_id' => $values[1]->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 250.00,
        ]);

        // The attach is audited as an activity row.
        $this->assertDatabaseHas('activity_log', [
            'user_id' => auth()->id(),
            'action' => 'option_group_attached',
        ]);
    }

    public function test_duplicate_attach_is_rejected(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeProduct();
        $group = $this->makeGroup();

        $this->attach($product, $group);

        $this->from(route('admin.products.edit', $product))
            ->post(route('admin.products.options.attach', $product), [
                'option_group_id' => $group->id,
            ])
            ->assertRedirect(route('admin.products.edit', $product))
            ->assertSessionHas('error', 'Option group is already attached to this product.');

        $this->assertSame(1, ProductOptionGroupProduct::query()
            ->where('product_id', $product->id)
            ->where('option_group_id', $group->id)
            ->count());
    }

    // ─────────────────────────────── Update ───────────────────────────────

    public function test_update_replaces_labels_and_price_modifiers(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeGroup();
        $link = $this->attach($product, $group);

        $values = $link->linkValues()->orderBy('sort_order')->get();

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '0',
            'values' => [
                ['id' => $values[0]->id, 'label' => 'cPanel Standard', 'sort_order' => 1, 'is_default' => '1'],
                ['id' => $values[1]->id, 'label' => 'Plesk Pro', 'sort_order' => 2, 'is_default' => '0'],
            ],
            'pricing' => [
                $values[0]->id => ['monthly' => 50.00, 'annual' => 500.00],
                $values[1]->id => ['monthly' => 300.00],
            ],
            'default_value_id' => $values[0]->id,
        ])->assertSessionHas('success');

        $fresh = $link->linkValues()->orderBy('sort_order')->get();
        $this->assertSame('cPanel Standard', $fresh[0]->label);
        $this->assertSame('Plesk Pro', $fresh[1]->label);

        // Pricing is replaced wholesale (delete-then-insert): new cycles exist,
        // the old monthly 0.00 rows are gone.
        $this->assertDatabaseHas('product_option_link_value_pricing', [
            'product_option_link_value_id' => $values[0]->id,
            'billing_cycle' => 'annual',
            'price_modifier' => 500.00,
        ]);
        $this->assertDatabaseHas('product_option_link_value_pricing', [
            'product_option_link_value_id' => $values[1]->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 300.00,
        ]);
        $this->assertDatabaseMissing('product_option_link_value_pricing', [
            'product_option_link_value_id' => $values[0]->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 0.00,
        ]);
    }

    public function test_update_moves_default_via_default_value_id(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeGroup();
        $link = $this->attach($product, $group);

        $values = $link->linkValues()->orderBy('sort_order')->get();
        $this->assertTrue($values[0]->is_default, 'First attached value must be the default.');

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '0',
            'values' => [
                ['id' => $values[0]->id, 'label' => 'cPanel', 'sort_order' => 1, 'is_default' => '0'],
                ['id' => $values[1]->id, 'label' => 'Plesk Web Admin', 'sort_order' => 2, 'is_default' => '0'],
            ],
            'default_value_id' => $values[1]->id,
        ])->assertSessionHas('success');

        $fresh = $link->linkValues()->orderBy('sort_order')->get();
        $this->assertFalse($fresh[0]->is_default);
        $this->assertTrue($fresh[1]->is_default, 'default_value_id must move the single default flag.');
    }

    public function test_default_radio_flag_moves_default_without_default_value_id(): void
    {
        // Mirrors the edit form: the Default radio in each value row flags
        // is_default directly — no default_value_id is submitted, so the
        // flagged value must become the default.
        $product = $this->makeProduct();
        $group = $this->makeGroup();
        $link = $this->attach($product, $group);

        $values = $link->linkValues()->orderBy('sort_order')->get();

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '0',
            'values' => [
                ['id' => $values[0]->id, 'label' => 'cPanel', 'sort_order' => 1, 'is_default' => '0'],
                ['id' => $values[1]->id, 'label' => 'Plesk Web Admin', 'sort_order' => 2, 'is_default' => '1'],
            ],
        ])->assertSessionHas('success');

        $fresh = $link->linkValues()->orderBy('sort_order')->get();
        $this->assertFalse($fresh[0]->is_default);
        $this->assertTrue($fresh[1]->is_default, 'The radio flag must move the default.');
    }

    public function test_update_toggles_customer_editable(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeGroup();
        $link = $this->attach($product, $group, editable: false);
        $this->assertFalse($link->customer_editable);

        $values = $link->linkValues()->orderBy('sort_order')->get();

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '1',
            'values' => [
                ['id' => $values[0]->id, 'label' => 'cPanel', 'sort_order' => 1, 'is_default' => '1'],
                ['id' => $values[1]->id, 'label' => 'Plesk Web Admin', 'sort_order' => 2, 'is_default' => '0'],
            ],
            'default_value_id' => $values[0]->id,
        ])->assertSessionHas('success');

        $this->assertTrue($link->fresh()->customer_editable);
    }

    public function test_update_rejects_deleting_the_current_default_without_replacement(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeGroup();
        $link = $this->attach($product, $group);

        $values = $link->linkValues()->orderBy('sort_order')->get();

        // The payload drops the default value (values[0]) entirely and
        // designates no replacement — the request must reject it.
        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '0',
            'values' => [
                ['id' => $values[1]->id, 'label' => 'Plesk Web Admin', 'sort_order' => 2, 'is_default' => '0'],
            ],
        ])->assertSessionHasErrors('option_links.'.$link->id.'.values');

        // Nothing changed on disk.
        $this->assertSame(2, $link->linkValues()->count());
        $this->assertSame($values[0]->id, $link->linkValues()->where('is_default', true)->value('id'));
    }

    // ─────────────────────── Continuous groups (unit pricing) ───────────────────────

    public function test_update_saves_unit_pricing_for_continuous_group(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeContinuousGroup();
        $link = $this->attach($product, $group);

        // The product edit page renders the unit-price field for the
        // continuous link inside the single update form (instead of the
        // discrete value table).
        $this->actingAsAdmin()
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('name="option_links['.$link->id.'][unit_pricing][monthly]"', false)
            ->assertDontSee('name="option_links['.$link->id.'][values][0][label]"', false);

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '1',
            'unit_pricing' => ['monthly' => 100.00, 'annual' => 1000.00],
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('product_option_link_pricing', [
            'product_option_group_product_id' => $link->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 100.00,
        ]);
        $this->assertDatabaseHas('product_option_link_pricing', [
            'product_option_group_product_id' => $link->id,
            'billing_cycle' => 'annual',
            'price_modifier' => 1000.00,
        ]);
    }

    public function test_update_replaces_unit_pricing_wholesale(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeContinuousGroup();
        $link = $this->attach($product, $group);

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '1',
            'unit_pricing' => ['monthly' => 100.00],
        ])->assertSessionHas('success');

        // Re-save with an annual price only: the monthly row must disappear.
        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '1',
            'unit_pricing' => ['annual' => 1200.00],
        ])->assertSessionHas('success');

        $this->assertSame(1, ProductOptionLinkPricing::where('product_option_group_product_id', $link->id)->count());
        $this->assertDatabaseHas('product_option_link_pricing', [
            'product_option_group_product_id' => $link->id,
            'billing_cycle' => 'annual',
            'price_modifier' => 1200.00,
        ]);
        $this->assertDatabaseMissing('product_option_link_pricing', [
            'product_option_group_product_id' => $link->id,
            'billing_cycle' => 'monthly',
        ]);
    }

    public function test_update_for_continuous_group_preserves_legacy_link_values(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeGroup('CPU Cores', 'slider'); // legacy slider carrying label values
        $link = $this->attach($product, $group);

        $this->assertSame(2, $link->linkValues()->count());

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '1',
            'unit_pricing' => ['monthly' => 100.00],
        ])->assertSessionHas('success');

        // Legacy value rows survive the unit-pricing save…
        $this->assertSame(2, $link->linkValues()->count());
        $this->assertSame('cPanel', $link->linkValues()->orderBy('sort_order')->first()->label);

        // …and the unit pricing was persisted alongside.
        $this->assertDatabaseHas('product_option_link_pricing', [
            'product_option_group_product_id' => $link->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 100.00,
        ]);
    }

    public function test_update_rejects_unsupported_unit_pricing_cycle(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeContinuousGroup();
        $link = $this->attach($product, $group);

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '1',
            'unit_pricing' => ['weekly' => 10.00],
        ])->assertSessionHasErrors('option_links.'.$link->id.'.unit_pricing.weekly');

        $this->assertSame(0, ProductOptionLinkPricing::where('product_option_group_product_id', $link->id)->count());
    }

    // ─────────────────────────────── Detach ───────────────────────────────

    public function test_detach_cascades_pivot_link_values_and_pricing(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeGroup();
        $link = $this->attach($product, $group);

        $this->assertSame(2, ProductOptionLinkValue::where('product_option_group_product_id', $link->id)->count());
        $this->assertSame(2, ProductOptionLinkValuePricing::count());

        $this->actingAsAdmin()
            ->from(route('admin.products.edit', $product))
            ->delete(route('admin.products.options.detach', [$product, $link]))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('product_option_group_product', ['id' => $link->id]);
        $this->assertSame(0, ProductOptionLinkValue::where('product_option_group_product_id', $link->id)->count());
        $this->assertSame(0, ProductOptionLinkValuePricing::count());

        $this->assertDatabaseHas('activity_log', [
            'user_id' => auth()->id(),
            'action' => 'option_group_detached',
        ]);
    }

    // ─────────────────────── Create flow (option payload) ───────────────────────

    public function test_store_attaches_selected_option_groups_with_pricing_at_creation(): void
    {
        $group = $this->makeGroup(); // dropdown: cPanel (0) / Plesk (250)
        $continuous = $this->makeContinuousGroup(); // slider, no catalog values
        $this->actingAsAdmin();

        $cPanelValue = ProductOptionValue::where('option_group_id', $group->id)
            ->where('label', 'cPanel')
            ->firstOrFail();

        $this->post(route('admin.products.store'), [
            'name' => 'Store Options VPS',
            'billing_cycle' => 'monthly',
            'provisioning_module' => 'manual',
            'status' => 'active',
            'gst_type' => 'standard',
            'pricing' => ['monthly' => ['price' => 499, 'setup_fee' => 0]],
            'option_groups' => [
                $continuous->id => [
                    'selected' => '1',
                    'unit_pricing' => ['monthly' => 75],
                ],
                $group->id => [
                    'selected' => '1',
                    'pricing' => [
                        $cPanelValue->id => ['monthly' => 25],
                        // Plesk value not submitted → keeps its copied pricing.
                    ],
                ],
            ],
        ])->assertRedirect();

        $product = Product::where('name', 'Store Options VPS')->firstOrFail();
        $this->assertSame(499.00, (float) $product->price);

        // Continuous group: the pivot exists and the unit pricing persisted.
        $continuousLink = ProductOptionGroupProduct::where('product_id', $product->id)
            ->where('option_group_id', $continuous->id)
            ->firstOrFail();
        $this->assertDatabaseHas('product_option_link_pricing', [
            'product_option_group_product_id' => $continuousLink->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 75.00,
        ]);

        // Discrete group: values were copied, the cPanel price overridden, and
        // the untouched Plesk value kept its copied 250.00.
        $discreteLink = ProductOptionGroupProduct::where('product_id', $product->id)
            ->where('option_group_id', $group->id)
            ->firstOrFail();

        $cPanelLinkValue = ProductOptionLinkValue::where('product_option_group_product_id', $discreteLink->id)
            ->where('label', 'cPanel')
            ->firstOrFail();
        $this->assertDatabaseHas('product_option_link_value_pricing', [
            'product_option_link_value_id' => $cPanelLinkValue->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 25.00,
        ]);

        $pleskLinkValue = ProductOptionLinkValue::where('product_option_group_product_id', $discreteLink->id)
            ->where('label', 'Plesk Web Admin')
            ->firstOrFail();
        $this->assertDatabaseHas('product_option_link_value_pricing', [
            'product_option_link_value_id' => $pleskLinkValue->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 250.00,
        ]);
    }

    public function test_store_skips_unselected_groups_and_rejects_unknown_billing_cycle(): void
    {
        $group = $this->makeGroup();
        $this->actingAsAdmin();

        // An unselected group is skipped entirely — no pivot, no pricing.
        $this->post(route('admin.products.store'), [
            'name' => 'Store Options VPS 2',
            'billing_cycle' => 'monthly',
            'provisioning_module' => 'manual',
            'status' => 'active',
            'gst_type' => 'standard',
            'option_groups' => [
                $group->id => ['selected' => '0'],
            ],
        ])->assertRedirect();

        $product = Product::where('name', 'Store Options VPS 2')->firstOrFail();
        $this->assertSame(0, ProductOptionGroupProduct::where('product_id', $product->id)->count());

        // A billing cycle outside Product::BILLING_CYCLES on the option payload
        // fails validation and aborts the create.
        $valueId = $group->values()->first()->id;

        $this->post(route('admin.products.store'), [
            'name' => 'Store Options VPS 3',
            'billing_cycle' => 'monthly',
            'provisioning_module' => 'manual',
            'status' => 'active',
            'gst_type' => 'standard',
            'option_groups' => [
                $group->id => [
                    'selected' => '1',
                    'pricing' => [$valueId => ['fortnightly' => 10]],
                ],
            ],
        ])->assertSessionHasErrors("option_groups.{$group->id}.pricing.{$valueId}.fortnightly");

        $this->assertNull(Product::where('name', 'Store Options VPS 3')->first());
    }

    public function test_store_saves_input_overrides_with_option_payload(): void
    {
        $continuous = $this->makeContinuousGroup();
        $this->actingAsAdmin();

        $this->post(route('admin.products.store'), [
            'name' => 'Store Options VPS Override',
            'billing_cycle' => 'monthly',
            'provisioning_module' => 'manual',
            'status' => 'active',
            'gst_type' => 'standard',
            'option_groups' => [
                $continuous->id => [
                    'selected' => '1',
                    'override_defaults' => '1',
                    'input_min' => 2,
                    'input_max' => 16,
                    'input_step' => '',
                    'unit_pricing' => ['monthly' => 50],
                ],
            ],
        ])->assertRedirect();

        $product = Product::where('name', 'Store Options VPS Override')->firstOrFail();
        $link = ProductOptionGroupProduct::where('product_id', $product->id)
            ->where('option_group_id', $continuous->id)
            ->firstOrFail();

        $this->assertSame('2.00', $link->input_min);
        $this->assertSame('16.00', $link->input_max);
        $this->assertNull($link->input_step, 'Blank override fields inherit the group value.');
    }

    // ─────────────────── Per-product input overrides ───────────────────

    public function test_update_saves_input_overrides_for_continuous_group(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeContinuousGroup();
        $link = $this->attach($product, $group);

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '1',
            'override_defaults' => '1',
            'input_min' => 2,
            'input_max' => 16,
            'input_step' => 2,
            'input_placeholder' => 'vCores',
            'unit_pricing' => ['monthly' => 100.00],
        ])->assertSessionHas('success');

        $link->refresh();
        $this->assertSame('2.00', $link->input_min);
        $this->assertSame('16.00', $link->input_max);
        $this->assertSame('2.00', $link->input_step);
        $this->assertSame('vCores', $link->input_placeholder);
    }

    public function test_update_clears_input_overrides_when_switch_is_off(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeContinuousGroup();
        $link = $this->attach($product, $group);
        $link->update(['input_min' => 2, 'input_max' => 16, 'input_step' => 2, 'input_placeholder' => 'vCores']);

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '1',
            'override_defaults' => '0',
            'unit_pricing' => ['monthly' => 100.00],
        ])->assertSessionHas('success');

        $link->refresh();
        $this->assertNull($link->input_min);
        $this->assertNull($link->input_max);
        $this->assertNull($link->input_step);
        $this->assertNull($link->input_placeholder);
    }

    public function test_update_rejects_max_below_min_override(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeContinuousGroup();
        $link = $this->attach($product, $group);

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '1',
            'override_defaults' => '1',
            'input_min' => 10,
            'input_max' => 5,
        ])->assertSessionHasErrors('option_links.'.$link->id.'.input_max');

        $link->refresh();
        $this->assertNull($link->input_min, 'Nothing is persisted when validation fails.');
        $this->assertNull($link->input_max);
    }

    // ─────────────────── Sync values from group ───────────────────

    public function test_sync_values_from_group_refreshes_the_product_snapshot(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeGroup(); // cPanel + Plesk
        $link = $this->attach($product, $group);

        // The group gains a value after the product was attached.
        $newValue = ProductOptionValue::create([
            'option_group_id' => $group->id,
            'label' => 'DirectAdmin',
            'sort_order' => 3,
        ]);
        ProductOptionPricing::create([
            'option_value_id' => $newValue->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 350.0,
        ]);

        $this->assertSame(2, $link->linkValues()->count(), 'Stale snapshot before the sync.');

        $this->actingAsAdmin()
            ->from(route('admin.products.edit', $product))
            ->post(route('admin.products.options.sync', [$product, $link]))
            ->assertSessionHas('success');

        $link->refresh();
        $this->assertSame(3, $link->linkValues()->count());
        $this->assertTrue($link->linkValues()->pluck('label')->contains('DirectAdmin'));

        $directAdmin = $link->linkValues()->where('label', 'DirectAdmin')->firstOrFail();
        $this->assertDatabaseHas('product_option_link_value_pricing', [
            'product_option_link_value_id' => $directAdmin->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 350.00,
        ]);

        // Defaults reset to the group's first value; customer_editable survives.
        $this->assertSame('cPanel', $link->linkValues()->where('is_default', true)->firstOrFail()->label);
        $this->assertDatabaseHas('product_option_group_product', [
            'id' => $link->id,
            'customer_editable' => false,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'user_id' => auth()->id(),
            'action' => 'option_group_synced',
        ]);
    }

    public function test_sync_is_rejected_for_continuous_group(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeContinuousGroup();
        $link = $this->attach($product, $group);

        $this->actingAsAdmin()
            ->from(route('admin.products.edit', $product))
            ->post(route('admin.products.options.sync', [$product, $link]))
            ->assertSessionHas('error');

        $this->assertSame(0, $link->linkValues()->count(), 'Continuous snapshots are never touched.');
    }

    public function test_update_product_saves_option_links_in_one_submit(): void
    {
        $product = $this->makeProduct();
        $group = $this->makeGroup(); // dropdown: cPanel + Plesk
        $continuous = $this->makeContinuousGroup(); // slider
        $sliderLink = $this->attach($product, $continuous);
        $dropdownLink = $this->attach($product, $group);

        $cPanel = $dropdownLink->linkValues()->where('label', 'cPanel')->firstOrFail();
        $plesk = $dropdownLink->linkValues()->where('label', 'Plesk Web Admin')->firstOrFail();

        $this->actingAsAdmin()
            ->put(route('admin.products.update', $product), [
                'name' => 'Single Form VPS',
                'billing_cycle' => 'monthly',
                'provisioning_module' => 'manual',
                'status' => 'active',
                'gst_type' => 'standard',
                'pricing' => ['monthly' => ['price' => 599, 'setup_fee' => 0]],
                'option_links' => [
                    $sliderLink->id => [
                        'customer_editable' => '1',
                        'override_defaults' => '1',
                        'input_min' => 2,
                        'input_max' => 16,
                        'unit_pricing' => ['monthly' => 75],
                    ],
                    $dropdownLink->id => [
                        'customer_editable' => '0',
                        'default_value_id' => $plesk->id,
                        'values' => [
                            ['id' => $cPanel->id, 'label' => 'cPanel', 'sort_order' => 1, 'is_default' => '0'],
                            ['id' => $plesk->id, 'label' => 'Plesk Web Admin', 'sort_order' => 2, 'is_default' => '1'],
                        ],
                        'pricing' => [
                            $cPanel->id => ['monthly' => 25],
                            $plesk->id => ['monthly' => 250],
                        ],
                    ],
                ],
            ])->assertRedirect(route('admin.products.edit', $product));

        // The product and every link were saved in one atomic submit.
        $product->refresh();
        $this->assertSame('Single Form VPS', $product->name);
        $this->assertSame('599.00', (string) $product->price);

        $sliderLink->refresh();
        $this->assertSame('75.00', $sliderLink->unitPricing()->where('billing_cycle', 'monthly')->firstOrFail()->price_modifier);
        $this->assertSame('2.00', $sliderLink->input_min);
        $this->assertTrue((bool) $sliderLink->customer_editable);

        $this->assertSame('Plesk Web Admin', $dropdownLink->linkValues()->where('is_default', true)->firstOrFail()->label);
        $this->assertSame('25.00', $cPanel->refresh()->pricing()->where('billing_cycle', 'monthly')->firstOrFail()->price_modifier);
    }

    // ─────────────────── Billing configuration (Phase 2) ───────────────────

    public function test_update_saves_billing_configuration_fields(): void
    {
        $product = $this->makeProduct();

        $this->actingAsAdmin()
            ->put(route('admin.products.update', $product), [
                'name' => $product->name,
                'billing_cycle' => 'monthly',
                'payment_type' => 'one_time',
                'quantity_behaviour' => 'scaling',
                'recurring_cycles_limit' => 12,
                'auto_terminate_value' => 30,
                'auto_terminate_unit' => 'days',
                'prorata_enabled' => '1',
                'prorata_date' => 15,
                'prorata_charge_next_month' => '1',
                'early_renewal_mode' => 'custom',
                'early_renewal_days' => ['monthly' => 7, 'annual' => 31],
                'provisioning_module' => 'manual',
                'status' => 'active',
                'gst_type' => 'standard',
            ])->assertRedirect(route('admin.products.edit', $product));

        $product->refresh();
        $this->assertSame('one_time', $product->payment_type);
        $this->assertSame('scaling', $product->quantity_behaviour);
        $this->assertSame(12, $product->recurring_cycles_limit);
        $this->assertSame(30, $product->auto_terminate_value);
        $this->assertSame('days', $product->auto_terminate_unit);
        $this->assertTrue($product->prorata_enabled);
        $this->assertSame(15, $product->prorata_date);
        $this->assertTrue($product->prorata_charge_next_month);
        $this->assertSame('custom', $product->early_renewal_mode);
        $this->assertSame(['monthly' => 7, 'annual' => 31], $product->early_renewal_days);
    }

    public function test_prorata_date_required_when_enabled(): void
    {
        $product = $this->makeProduct();

        $this->actingAsAdmin()
            ->put(route('admin.products.update', $product), [
                'name' => $product->name,
                'billing_cycle' => 'monthly',
                'provisioning_module' => 'manual',
                'status' => 'active',
                'gst_type' => 'standard',
                'prorata_enabled' => '1',
            ])->assertSessionHasErrors('prorata_date');

        $this->assertFalse($product->fresh()->prorata_enabled, 'Nothing persists when validation fails.');
    }

    public function test_early_renewal_rejects_unknown_cycle(): void
    {
        $product = $this->makeProduct();

        $this->actingAsAdmin()
            ->put(route('admin.products.update', $product), [
                'name' => $product->name,
                'billing_cycle' => 'monthly',
                'provisioning_module' => 'manual',
                'status' => 'active',
                'gst_type' => 'standard',
                'early_renewal_mode' => 'custom',
                'early_renewal_days' => ['weekly' => 5],
            ])->assertSessionHasErrors('early_renewal_days.weekly');
    }

    public function test_one_time_product_prices_options_on_one_time_cycle(): void
    {
        $product = $this->makeProduct();
        $product->update(['payment_type' => 'one_time']);

        $group = $this->makeContinuousGroup();
        $link = $this->attach($product, $group);

        $this->updateLinkThroughProduct($product, $link, [
            'customer_editable' => '1',
            'unit_pricing' => ['one_time' => 250.00],
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('product_option_link_pricing', [
            'product_option_group_product_id' => $link->id,
            'billing_cycle' => 'one_time',
            'price_modifier' => 250.00,
        ]);
    }

    public function test_option_links_mirror_enabled_product_cycles(): void
    {
        $product = $this->makeProduct();
        $continuous = $this->makeContinuousGroup();
        $link = $this->attach($product, $continuous);

        // No pricing ladder yet — the option section falls back to the
        // product's default cycle.
        $this->actingAsAdmin()
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('name="option_links['.$link->id.'][unit_pricing][monthly]"', false)
            ->assertDontSee('name="option_links['.$link->id.'][unit_pricing][annual]"', false);

        // Enable monthly + annual on the product ladder — the option section
        // mirrors exactly the enabled cycles.
        ProductPricing::create([
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'price' => 100,
            'setup_fee' => 0,
        ]);
        ProductPricing::create([
            'product_id' => $product->id,
            'billing_cycle' => 'annual',
            'price' => 4990,
            'setup_fee' => 0,
        ]);

        $this->actingAsAdmin()
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('name="option_links['.$link->id.'][unit_pricing][monthly]"', false)
            ->assertSee('name="option_links['.$link->id.'][unit_pricing][annual]"', false)
            ->assertDontSee('name="option_links['.$link->id.'][unit_pricing][quarterly]"', false);
    }

    public function test_edit_page_renders_sortable_option_link_cards_with_drag_handles(): void
    {
        $product = $this->makeProduct();
        $groupA = $this->makeGroup('Panel A');
        $groupB = $this->makeGroup('Panel B');
        $linkA = $this->attach($product, $groupA);
        $linkB = $this->attach($product, $groupB);

        $this->actingAsAdmin()
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('id="option-links-sortable"', false)
            ->assertSee('data-sortable', false)
            ->assertSee('option-link-drag-handle', false)
            ->assertSee('name="option_links['.$linkA->id.'][sort_order]"', false)
            ->assertSee('name="option_links['.$linkB->id.'][sort_order]"', false);

        // Attach order becomes the initial display order.
        $linkA->refresh();
        $linkB->refresh();
        $this->assertSame(1, (int) $linkA->sort_order);
        $this->assertSame(2, (int) $linkB->sort_order);
    }

    public function test_edit_page_saves_reordered_option_link_sort_order(): void
    {
        $product = $this->makeProduct();
        $groupA = $this->makeGroup('Panel A');
        $groupB = $this->makeGroup('Panel B');
        $linkA = $this->attach($product, $groupA); // sort_order 1
        $linkB = $this->attach($product, $groupB); // sort_order 2

        // Swap the order through the product update form (the drag reorder
        // writes the new sequence into the hidden sort_order inputs).
        $this->updateLinkThroughProduct($product, $linkA, [
            'customer_editable' => '0',
            'sort_order' => 2,
        ]);
        $this->updateLinkThroughProduct($product, $linkB, [
            'customer_editable' => '0',
            'sort_order' => 1,
        ]);

        // The relation returns links in the reordered display order.
        $ordered = $product->refresh()->optionLinks->pluck('id')->all();
        $this->assertSame([$linkB->id, $linkA->id], $ordered);

        // New attachments append at the end of the order.
        $groupC = $this->makeGroup('Panel C');
        $linkC = $this->attach($product, $groupC);
        $this->assertSame(3, (int) $linkC->refresh()->sort_order);
    }
}
