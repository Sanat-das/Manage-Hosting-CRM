<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkValue;
use App\Models\Role;
use App\Models\User;
use App\Services\ProductOptionLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin CRUD for configurable option GROUPS (the catalog side).
 *
 * Covers the group's identity fields — the display `name`, the machine `key`
 * that provisioning acts on, and the `unit` a bare number is rendered with
 * ("200" is meaningless; "200 GB" is the feature) — and the two ways this
 * page used to destroy money silently: a raw `products()->sync()` that
 * attached valueless links and detached priced ones.
 */
class ProductOptionGroupAdminTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $permission = Permission::firstOrCreate(['name' => 'products.options'], ['label' => 'Manage Product Options']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'name' => 'Cloud VPS',
            'price' => 199.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'active',
        ]);
    }

    public function test_the_create_form_offers_a_unit_field(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.product-options.create'))
            ->assertOk()
            ->assertSee('name="unit"', false);
    }

    public function test_store_persists_the_unit(): void
    {
        $product = $this->makeProduct();

        $this->actingAsAdmin()
            ->post(route('admin.product-options.store'), [
                'product_ids' => [$product->id],
                'name' => 'Storage',
                'unit' => 'GB',
                'type' => 'slider',
                'input_min' => 10,
                'input_max' => 500,
                'input_step' => 10,
                'sort_order' => 1,
            ])
            ->assertRedirect(route('admin.product-options.index'));

        $group = ProductOptionGroup::sole();

        $this->assertSame('GB', $group->unit);
        $this->assertSame('storage', $group->key, 'The machine key is still derived from the name.');
    }

    public function test_store_accepts_an_empty_unit(): void
    {
        // Discrete features (Backup: Daily / Weekly) speak for themselves and
        // need no unit — it stays null rather than becoming an empty string.
        $product = $this->makeProduct();

        $this->actingAsAdmin()
            ->post(route('admin.product-options.store'), [
                'product_ids' => [$product->id],
                'name' => 'Backup',
                'unit' => '',
                'type' => 'dropdown',
                'sort_order' => 1,
            ])
            ->assertRedirect(route('admin.product-options.index'));

        $this->assertNull(ProductOptionGroup::sole()->unit);
    }

    public function test_update_changes_the_unit_without_rekeying_the_group(): void
    {
        $product = $this->makeProduct();

        $group = ProductOptionGroup::create([
            'name' => 'Memory',
            'sort_order' => 1,
            'type' => 'slider',
        ]);
        $group->products()->sync([$product->id]);

        $this->assertSame('memory', $group->key);

        $this->actingAsAdmin()
            ->put(route('admin.product-options.update', $group), [
                'product_ids' => [$product->id],
                'name' => 'Memory',
                'unit' => 'GB',
                'type' => 'slider',
                'sort_order' => 1,
            ])
            ->assertRedirect(route('admin.product-options.index'));

        $group->refresh();

        $this->assertSame('GB', $group->unit);
        $this->assertSame('memory', $group->key, 'An existing key is stable — provisioning maps to it.');
    }

    public function test_the_edit_form_shows_the_saved_unit(): void
    {
        $group = ProductOptionGroup::create([
            'name' => 'Storage',
            'unit' => 'TB',
            'sort_order' => 1,
            'type' => 'number',
        ]);

        $this->actingAsAdmin()
            ->get(route('admin.product-options.edit', $group))
            ->assertOk()
            ->assertSee('name="unit"', false)
            ->assertSee('value="TB"', false);
    }

    /**
     * A group with two priced values, attached to the product through the link
     * service — i.e. a product that really charges for this feature.
     *
     * @return array{0: ProductOptionGroup, 1: ProductOptionGroupProduct}
     */
    private function attachedGroup(Product $product): array
    {
        $group = ProductOptionGroup::create(['name' => 'Backup', 'type' => 'dropdown', 'sort_order' => 1]);

        foreach ([['Daily', 199.00], ['Weekly', 99.00]] as [$label, $price]) {
            $value = $group->values()->create(['label' => $label, 'sort_order' => 0]);
            $value->pricing()->create(['billing_cycle' => 'monthly', 'price_modifier' => $price]);
        }

        $link = app(ProductOptionLinkService::class)->attachGroup($product, $group);

        return [$group, $link];
    }

    public function test_attaching_a_product_snapshots_the_group_values_onto_it(): void
    {
        // A raw pivot sync() left the link with zero values, and because links
        // are required by default the storefront then rendered a control the
        // customer could not answer.
        $product = $this->makeProduct();

        $this->actingAsAdmin()
            ->post(route('admin.product-options.store'), [
                'product_ids' => [$product->id],
                'name' => 'Backup',
                'type' => 'dropdown',
                'sort_order' => 1,
                'values' => [
                    ['label' => 'Daily', 'sort_order' => 0, 'pricing' => ['monthly' => ['price_modifier' => '199.00']]],
                    ['label' => 'Weekly', 'sort_order' => 1, 'pricing' => ['monthly' => ['price_modifier' => '99.00']]],
                ],
            ])
            ->assertRedirect(route('admin.product-options.index'));

        $link = ProductOptionGroupProduct::sole();

        $this->assertSame($product->id, (int) $link->product_id);
        $this->assertSame(2, $link->linkValues()->count(), 'The attach snapshotted the catalog values onto the product.');
        $this->assertSame(
            '199.00',
            (string) ProductOptionLinkValue::where('label', 'Daily')->sole()->pricing()->where('billing_cycle', 'monthly')->value('price_modifier'),
            'The per-cycle price came across with the value — an unpriced snapshot charges nothing.'
        );
    }

    public function test_the_default_value_radio_decides_what_a_fixed_option_charges_for(): void
    {
        // Attaching used to flag whichever value happened to be copied first.
        $product = $this->makeProduct();

        $this->actingAsAdmin()
            ->post(route('admin.product-options.store'), [
                'product_ids' => [$product->id],
                'name' => 'Backup',
                'type' => 'dropdown',
                'sort_order' => 1,
                'default_value_index' => '1',
                'values' => [
                    ['label' => 'Daily', 'sort_order' => 0],
                    ['label' => 'Weekly', 'sort_order' => 1],
                ],
            ])
            ->assertRedirect(route('admin.product-options.index'));

        $this->assertSame('Weekly', ProductOptionGroup::sole()->values()->where('is_default', true)->value('label'));
        $this->assertSame(
            'Weekly',
            ProductOptionLinkValue::where('is_default', true)->sole()->label,
            'The catalog default carried onto the product snapshot.'
        );
    }

    public function test_update_refuses_to_detach_a_product_whose_link_is_priced(): void
    {
        // Detaching deletes the pivot, and product_option_link_values cascades
        // on it — so an unticked box used to silently delete the pricing an
        // admin built on the product page.
        $product = $this->makeProduct();
        [$group, $link] = $this->attachedGroup($product);

        $this->actingAsAdmin()
            ->from(route('admin.product-options.edit', $group))
            ->put(route('admin.product-options.update', $group), [
                'product_ids' => [], // "detach everything"
                'name' => 'Backup',
                'type' => 'dropdown',
                'sort_order' => 1,
            ])
            ->assertSessionHasErrors('product_ids');

        $this->assertDatabaseHas('product_option_group_product', ['id' => $link->id]);
        $this->assertSame(2, $link->linkValues()->count(), 'The per-product values survived the refused detach.');
    }

    public function test_update_leaves_attachments_alone_when_the_form_posts_none(): void
    {
        // The edit form no longer posts product_ids at all; an absent key must
        // mean "leave them alone", not "detach everything".
        $product = $this->makeProduct();
        [$group, $link] = $this->attachedGroup($product);

        $this->actingAsAdmin()
            ->put(route('admin.product-options.update', $group), [
                'name' => 'Backups',
                'type' => 'dropdown',
                'sort_order' => 1,
            ])
            ->assertRedirect(route('admin.product-options.index'));

        $this->assertDatabaseHas('product_option_group_product', ['id' => $link->id]);
        $this->assertSame('Backups', $group->fresh()->name);
    }

    public function test_editing_a_value_does_not_reprice_an_attached_product_until_pushed(): void
    {
        // The link values are a snapshot on purpose — a product may be priced
        // differently — so catalog edits must not leak into it silently.
        $product = $this->makeProduct();
        [$group, $link] = $this->attachedGroup($product);

        $this->actingAsAdmin()
            ->put(route('admin.product-options.update', $group), [
                'name' => 'Backup',
                'type' => 'dropdown',
                'sort_order' => 1,
                'values' => [
                    ['label' => 'Daily', 'sort_order' => 0, 'pricing' => ['monthly' => ['price_modifier' => '499.00']]],
                    ['label' => 'Weekly', 'sort_order' => 1, 'pricing' => ['monthly' => ['price_modifier' => '99.00']]],
                ],
            ])
            ->assertRedirect(route('admin.product-options.index'));

        $dailyRate = fn () => (string) ProductOptionLinkValue::where('product_option_group_product_id', $link->id)
            ->where('label', 'Daily')->sole()
            ->pricing()->where('billing_cycle', 'monthly')->value('price_modifier');

        $this->assertSame('199.00', $dailyRate(), 'The product still charges its own snapshotted price.');

        $this->actingAsAdmin()
            ->from(route('admin.product-options.edit', $group))
            ->post(route('admin.product-options.push', $group))
            ->assertRedirect(route('admin.product-options.edit', $group));

        $this->assertSame('499.00', $dailyRate(), 'Pushing re-snapshotted the catalog price onto the product.');
    }

    public function test_destroy_refuses_while_a_product_still_prices_the_group(): void
    {
        // Deleting the group cascades through the pivot to the per-product
        // pricing, which no confirm dialog would make recoverable.
        $product = $this->makeProduct();
        [$group] = $this->attachedGroup($product);

        $this->actingAsAdmin()
            ->from(route('admin.product-options.edit', $group))
            ->delete(route('admin.product-options.destroy', $group))
            ->assertSessionHasErrors('error');

        $this->assertDatabaseHas('product_option_groups', ['id' => $group->id]);
    }

    public function test_destroy_still_works_when_nothing_prices_the_group(): void
    {
        $group = ProductOptionGroup::create(['name' => 'Support', 'type' => 'dropdown', 'sort_order' => 1]);

        $this->actingAsAdmin()
            ->delete(route('admin.product-options.destroy', $group))
            ->assertRedirect(route('admin.product-options.index'));

        $this->assertDatabaseMissing('product_option_groups', ['id' => $group->id]);
    }

    public function test_the_edit_form_lists_attached_products_read_only(): void
    {
        $product = $this->makeProduct();
        [$group] = $this->attachedGroup($product);

        $this->actingAsAdmin()
            ->get(route('admin.product-options.edit', $group))
            ->assertOk()
            ->assertSee('Used by 1 product')
            ->assertSee('Cloud VPS')
            ->assertDontSee('name="product_ids[]"', false);
    }

    public function test_a_checkbox_group_stores_its_maximum_selections_in_input_max(): void
    {
        // The form posts both meanings of input_max at once (hiding a field
        // must not blank it), so the type has to decide which one lands.
        $product = $this->makeProduct();

        $this->actingAsAdmin()
            ->post(route('admin.product-options.store'), [
                'product_ids' => [$product->id],
                'name' => 'Add-ons',
                'type' => 'checkbox',
                'sort_order' => 1,
                'input_max' => 500,      // the range bound, meaningless here
                'max_selections' => 3,
                'values' => [['label' => 'Firewall', 'sort_order' => 0]],
            ])
            ->assertRedirect(route('admin.product-options.index'));

        $this->assertSame('3.00', (string) ProductOptionGroup::sole()->input_max);
    }

    public function test_a_slider_group_keeps_its_range_bound_in_input_max(): void
    {
        $product = $this->makeProduct();

        $this->actingAsAdmin()
            ->post(route('admin.product-options.store'), [
                'product_ids' => [$product->id],
                'name' => 'Memory',
                'type' => 'slider',
                'sort_order' => 1,
                'input_min' => 1,
                'input_max' => 64,
                'input_step' => 1,
                'max_selections' => 3, // the checkbox meaning, meaningless here
            ])
            ->assertRedirect(route('admin.product-options.index'));

        $this->assertSame('64.00', (string) ProductOptionGroup::sole()->input_max);
    }

    public function test_unit_is_length_capped(): void
    {
        $product = $this->makeProduct();

        $this->actingAsAdmin()
            ->from(route('admin.product-options.create'))
            ->post(route('admin.product-options.store'), [
                'product_ids' => [$product->id],
                'name' => 'Storage',
                'unit' => str_repeat('X', 21),
                'type' => 'slider',
                'sort_order' => 1,
            ])
            ->assertSessionHasErrors('unit');

        $this->assertSame(0, ProductOptionGroup::count());
    }
}
