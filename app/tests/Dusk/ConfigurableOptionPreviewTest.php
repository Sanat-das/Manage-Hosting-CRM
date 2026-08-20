<?php

namespace Tests\Dusk;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductGroup;
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
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Browser-level coverage for the continuous (unit-priced) configurable
 * options feature.
 *
 * The store test drives the live slider price preview end-to-end in a real
 * browser: it logs in as a client, opens the product page, moves the slider
 * with the keyboard and asserts the estimated total tracks base + unit ×
 * value. The admin tests verify the unit-price grid on the product edit page
 * renders in place of the discrete value table and that a unit price change
 * saves through the UI, and cover the create flow end-to-end: a product is
 * created through the browser, both group types are attached via the picker,
 * and the type-aware split + unit-price save are asserted on the new product.
 *
 * Runtime contract: the app must be served with the Dusk environment
 * (`php artisan serve --env=dusk` on the APP_URL in .env.dusk.local) so the
 * browser and the test process share database/dusk.sqlite.
 *
 * Database strategy: the schema is migrated FRESH once per process and never
 * rolled back between tests. The served app holds a long-lived connection to
 * the same sqlite file, and DatabaseMigrations' per-test migrate:fresh +
 * teardown migrate:rollback can expose a window where the app's requests hit
 * dropped tables ("no such table"). setUp() instead deletes only this test's
 * fixtures (by stable identifiers), which keeps runs idempotent without ever
 * tearing the schema down underneath the running server.
 */
class ConfigurableOptionPreviewTest extends DuskTestCase
{
    private static bool $schemaMigrated = false;

    private ProductGroup $category;

    private Product $product;

    private ProductOptionGroupProduct $link;

    private ProductOptionGroupProduct $dropdownLink;

    private User $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schemaMigrated) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            self::$schemaMigrated = true;
        }

        $this->removeFixtureRows();

        $this->category = ProductGroup::create([
            'name' => 'Cloud',
            'slug' => 'cloud',
            'status' => 'active',
            'is_hosting' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Cloud VPS',
            'product_group_id' => $this->category->id,
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

        // Continuous slider option group attached to the product (customer
        // editable) with a ₹100.00 per-unit monthly price — 1 vCore @ ₹100.
        $group = ProductOptionGroup::create([
            'name' => 'CPU Cores',
            'sort_order' => 1,
            'type' => 'slider',
            'input_min' => 1,
            'input_max' => 32,
            'input_step' => 1,
        ]);

        $this->link = ProductOptionGroupProduct::create([
            'product_id' => $this->product->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        ProductOptionLinkPricing::create([
            'product_option_group_product_id' => $this->link->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 100.00,
        ]);

        // Discrete dropdown option group also attached to the same product, so
        // the admin page's type-aware rendering can be verified against a
        // group that must still use the per-value table (never unit pricing).
        $dropdownGroup = ProductOptionGroup::create([
            'name' => 'Control Panel',
            'sort_order' => 2,
            'type' => 'dropdown',
        ]);

        $this->dropdownLink = ProductOptionGroupProduct::create([
            'product_id' => $this->product->id,
            'option_group_id' => $dropdownGroup->id,
            'customer_editable' => true,
        ]);

        // The catalog group now carries three values (Enterprise Support was
        // added after the products were attached)...
        foreach ([['Standard Support', 0.0], ['Priority Support', 149.0], ['Enterprise Support', 299.0]] as $index => [$label, $modifier]) {
            $optionValue = ProductOptionValue::create([
                'option_group_id' => $dropdownGroup->id,
                'label' => $label,
                'sort_order' => $index + 1,
            ]);

            ProductOptionPricing::create([
                'option_value_id' => $optionValue->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $modifier,
            ]);
        }

        // ...but this product's snapshot was taken before that, so it only
        // carries the first two values — a stale copy the sync action fixes.
        foreach ([['Standard Support', 0.0], ['Priority Support', 149.0]] as $index => [$label, $modifier]) {
            $value = ProductOptionLinkValue::create([
                'product_option_group_product_id' => $this->dropdownLink->id,
                'label' => $label,
                'is_default' => $index === 0,
                'sort_order' => $index + 1,
            ]);

            ProductOptionLinkValuePricing::create([
                'product_option_link_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $modifier,
            ]);
        }

        // Client user with a linked customer record (the client portal's
        // customer.record middleware requires one).
        $this->client = User::factory()->create([
            'email' => 'dusk-client@example.com',
            'role' => 'client',
        ]);
        Customer::create([
            'user_id' => $this->client->id,
            'company' => 'Dusk Corp',
            'status' => 'active',
        ]);

        // Admin user with dashboard + product edit permissions.
        $this->admin = User::factory()->create([
            'email' => 'dusk-admin@example.com',
            'role' => 'admin',
        ]);
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['dashboard.view', 'products.view', 'products.create', 'products.edit'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->admin->assignRole('admin');
    }

    /**
     * Remove this test's fixtures by their stable identifiers. The schema
     * itself is left untouched (it is migrated fresh once per process), so
     * tests stay idempotent across runs and independent of each other without
     * ever dropping tables underneath the served app.
     */
    private function removeFixtureRows(): void
    {
        $userIds = User::whereIn('email', ['dusk-client@example.com', 'dusk-admin@example.com'])->pluck('id');
        Customer::whereIn('user_id', $userIds)->delete();
        User::whereIn('id', $userIds)->delete();

        $productNames = ['Cloud VPS', 'Managed VPS', 'Dusk Managed', 'Storage VPS', 'Bundle VPS'];

        $linkIds = ProductOptionGroupProduct::query()
            ->whereHas('group', fn ($query) => $query->whereIn('name', ['CPU Cores', 'Control Panel', 'Storage (TB)', 'Add-ons']))
            ->orWhereHas('product', fn ($query) => $query->whereIn('name', $productNames))
            ->pluck('id');
        ProductOptionLinkPricing::whereIn('product_option_group_product_id', $linkIds)->delete();
        ProductOptionGroupProduct::whereIn('id', $linkIds)->delete();
        ProductOptionGroup::whereIn('name', ['CPU Cores', 'Control Panel', 'Storage (TB)', 'Add-ons'])->delete();

        $productIds = Product::whereIn('name', $productNames)->pluck('id');
        ProductPricing::whereIn('product_id', $productIds)->delete();
        Product::whereIn('id', $productIds)->delete();
        ProductGroup::where('name', 'Cloud')->delete();
    }

    public function test_slider_live_price_preview_updates_on_the_store_product_page(): void
    {
        $this->browse(function (Browser $browser) {
            // Log the client in via the shared Fortify login.
            $browser->visit('/login')
                ->type('email', $this->client->email)
                ->type('password', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/client/dashboard');

            $browser->visit('/client/store/'.$this->product->id)
                ->waitForText('CPU Cores');

            // Slider starts at min (1 vCore): base 199.00 + 1 × 100.00 = 299.00.
            $browser->waitForText('₹299.00')
                ->assertSeeIn('#live-price-total', '₹299.00')
                ->assertValue('#option-'.$this->link->id, '1');

            // The per-unit hint is rendered under the slider for the current cycle.
            $browser->assertSeeIn('[data-unit-price="'.$this->link->id.'"]', '₹100.00 per unit / monthly');

            // Move the slider up 7 steps with the arrow keys (1 → 8 vCores).
            $browser->keys('#option-'.$this->link->id, ...array_fill(0, 7, '{ARROW_RIGHT}'));

            // The live total follows: base 199.00 + 8 × 100.00 = 999.00.
            $browser->waitForText('₹999.00')
                ->assertSeeIn('#live-price-total', '₹999.00')
                ->assertValue('#option-'.$this->link->id, '8')
                ->assertSeeIn('[data-slider-value="option-'.$this->link->id.'"]', '8');
        });
    }

    /**
     * A product with a step-0.5 `number` option priced at ₹99.99/unit.
     *
     * @return array{0: Product, 1: ProductOptionGroupProduct}
     */
    private function makeDecimalStorageProduct(): array
    {
        $category = ProductGroup::where('name', 'Cloud')->firstOrFail();

        $product = Product::create([
            'name' => 'Storage VPS',
            'product_group_id' => $category->id,
            'price' => 199.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
            'require_domain' => false,
        ]);

        ProductPricing::create([
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'price' => 199.00,
            'setup_fee' => 0,
        ]);

        $group = ProductOptionGroup::create([
            'name' => 'Storage (TB)',
            'sort_order' => 9,
            'type' => 'number',
            'input_min' => 0.5,
            'input_max' => 10,
            'input_step' => 0.5,
        ]);

        $link = ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        ProductOptionLinkPricing::create([
            'product_option_group_product_id' => $link->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 99.99,
        ]);

        return [$product, $link];
    }

    public function test_client_decimal_continuous_option_quantity_and_rounded_price_in_cart_and_order(): void
    {
        // A step-driven (0.5) number option priced at ₹99.99/unit: ordering
        // 2.5 units charges 2.5 × ₹99.99 = ₹249.975 → ₹249.98 on the ₹199.00
        // base → ₹448.98. The decimal quantity and the rounded price must
        // surface identically in the live preview, the cart and the order.
        [$product, $link] = $this->makeDecimalStorageProduct();

        $this->browse(function (Browser $browser) use ($product, $link) {
            $browser->visit('/login')
                ->type('email', $this->client->email)
                ->type('password', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/client/dashboard');

            // Enter the decimal value: the live preview shows the rounded
            // price 199.00 + 249.98 = 448.98.
            $browser->visit('/client/store/'.$product->id)
                ->waitForText('Storage (TB)')
                ->type('#option-'.$link->id, '2.5')
                ->assertValue('#option-'.$link->id, '2.5')
                ->waitUntil('document.getElementById("live-price-total").textContent === "₹448.98"');

            // Add to cart: the cart shows the decimal quantity and the
            // rounded unit price + option modifier.
            $browser->press('Add to Cart')
                ->waitForLocation('/client/store');

            $browser->visit('/client/store/cart')
                ->waitForText('Storage VPS')
                ->assertSee('Storage (TB): 2.5')
                ->assertSee('+₹249.98/mo')
                ->assertSee('₹448.98');

            // Checkout and place the order: the confirmation shows the same.
            $browser->visit('/client/store/checkout')
                ->press('Place Order')
                ->waitUntil('window.location.pathname.match(/^\/client\/store\/order\/\d+$/) !== null')
                ->assertSee('Storage (TB): 2.5')
                ->assertSee('₹448.98');
        });

        // The persisted order carries the decimal selection + rounded price.
        $order = Order::query()
            ->whereHas('customer.user', fn ($user) => $user->where('email', 'dusk-client@example.com'))
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($product->id, $order->product_id);
        $this->assertSame('448.98', (string) $order->total);

        $item = $order->items()->firstOrFail();
        $this->assertSame('448.98', (string) $item->unit_price);
        $this->assertSame(2.5, (float) $item->config_options['options'][0]['selected'], 'The decimal quantity is snapshotted.');
    }

    public function test_client_storefront_blocks_out_of_step_decimal_option(): void
    {
        // An off-grid decimal (2.4 on a 0.5 step) is blocked by the native
        // step attribute on the control, and the shared validation rules
        // reject the same value server-side — the two layers agree.
        [$product, $link] = $this->makeDecimalStorageProduct();

        $this->browse(function (Browser $browser) use ($product, $link) {
            $browser->visit('/login')
                ->type('email', $this->client->email)
                ->type('password', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/client/dashboard');

            $browser->visit('/client/store/'.$product->id)
                ->waitForText('Storage (TB)')
                ->type('#option-'.$link->id, '2.4');

            // The native constraint flags the value as off the step grid.
            $this->assertTrue(
                $browser->script("return document.getElementById('option-".$link->id."').validity.stepMismatch;")[0],
                '2.4 is not a valid step on the 0.5 grid (min 0.5).'
            );

            // Submitting is blocked by native validation: no navigation away
            // from the product page, so nothing reaches the cart.
            $browser->press('Add to Cart')
                ->pause(1000)
                ->assertPathIs('/client/store/'.$product->id);
        });

        $this->assertEmpty(session('cart'), 'The out-of-step value never reaches the cart.');
    }

    public function test_client_checkbox_options_grey_out_past_the_selection_cap(): void
    {
        // A checkbox group with input_max = 2: once two options are checked,
        // the remaining unchecked ones grey out and disable — mirroring the
        // server's max rule. Unchecking one re-enables them.
        $category = ProductGroup::where('name', 'Cloud')->firstOrFail();

        $product = Product::create([
            'name' => 'Bundle VPS',
            'product_group_id' => $category->id,
            'price' => 299.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
            'require_domain' => false,
        ]);

        ProductPricing::create([
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'price' => 299.00,
            'setup_fee' => 0,
        ]);

        $group = ProductOptionGroup::create([
            'name' => 'Add-ons',
            'sort_order' => 9,
            'type' => 'checkbox',
            'input_max' => 2,
        ]);

        $link = ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        $linkValueIds = [];
        foreach (['Backup', 'SSL', 'DDoS'] as $index => $label) {
            $value = ProductOptionValue::create([
                'option_group_id' => $group->id,
                'label' => $label,
                'sort_order' => $index + 1,
            ]);

            ProductOptionPricing::create([
                'option_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => 0.0,
            ]);

            $linkValue = ProductOptionLinkValue::create([
                'product_option_group_product_id' => $link->id,
                'label' => $label,
                'is_default' => $index === 0,
                'sort_order' => $index + 1,
            ]);

            ProductOptionLinkValuePricing::create([
                'product_option_link_value_id' => $linkValue->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => 0.0,
            ]);

            $linkValueIds[] = $linkValue->id;
        }

        [$first, $second, $third] = $linkValueIds;

        $this->browse(function (Browser $browser) use ($product, $link, $first, $second, $third) {
            $browser->visit('/login')
                ->type('email', $this->client->email)
                ->type('password', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/client/dashboard');

            $browser->visit('/client/store/'.$product->id)
                ->waitForText('Add-ons');

            // On load the first option is checked by default; the others are
            // enabled (below the cap).
            $this->assertFalse(
                $browser->script("return document.getElementById('option-".$link->id."-".$third."').disabled;")[0]
            );

            // Checking a second option reaches the cap of 2 → the third greys
            // out and disables.
            $browser->check('#option-'.$link->id.'-'.$second);
            $this->assertTrue(
                $browser->script("return document.getElementById('option-".$link->id."-".$third."').disabled;")[0],
                'The third option must disable at the selection cap.'
            );
            $this->assertTrue(
                $browser->script("return document.getElementById('option-".$link->id."-".$third."').closest('[data-checkbox-option]').classList.contains('option-cap-limited');")[0],
                'The third option must be greyed out at the selection cap.'
            );

            // Unchecking one drops below the cap → the third re-enables.
            $browser->uncheck('#option-'.$link->id.'-'.$first);
            $this->assertFalse(
                $browser->script("return document.getElementById('option-".$link->id."-".$third."').disabled;")[0],
                'The third option must re-enable below the cap.'
            );
        });
    }

    public function test_admin_product_edit_page_renders_and_saves_unit_pricing(): void
    {
        $sliderLinkId = $this->link->id;
        $dropdownLinkId = $this->dropdownLink->id;

        $this->browse(function (Browser $browser) use ($sliderLinkId, $dropdownLinkId) {
            $browser->visit('/login')
                ->type('email', $this->admin->email)
                ->type('password', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin/dashboard');

            // Type-aware rendering: the continuous (slider) card shows the
            // unit-price field for the product's default billing cycle — and
            // never the discrete per-value table.
            $browser->visit('/admin/products/'.$this->product->id.'/edit')
                ->click('#edit-tab-options')
                ->waitForText('CPU Cores')
                ->within('.option-link-card:has(input[name="option_links['.$sliderLinkId.'][unit_pricing][monthly]"])', function (Browser $card) use ($sliderLinkId) {
                    $card->assertPresent('input[name="option_links['.$sliderLinkId.'][unit_pricing][monthly]"]')
                        ->assertMissing('input[name="option_links['.$sliderLinkId.'][unit_pricing][annual]"]')
                        ->assertMissing('input[name="option_links['.$sliderLinkId.'][values][0][label]"]');

                    // The Min/Max/Step/Placeholder override block shows the
                    // group's values and stays disabled until toggled.
                    $card->assertValue('input[name="option_links['.$sliderLinkId.'][input_min]"]', '1.00')
                        ->assertValue('input[name="option_links['.$sliderLinkId.'][input_max]"]', '32.00')
                        ->assertValue('input[name="option_links['.$sliderLinkId.'][input_step]"]', '1.00')
                        ->assertDisabled('input[name="option_links['.$sliderLinkId.'][input_min]"]');
                });

            // The discrete (dropdown) card keeps the per-value table — and
            // must never render the unit-price grid.
            $browser->within('.option-link-card:has(input[name="option_links['.$dropdownLinkId.'][values][0][label]"])', function (Browser $card) use ($dropdownLinkId) {
                $card->assertPresent('input[name="option_links['.$dropdownLinkId.'][values][0][label]"]')
                    ->assertValue('input[name="option_links['.$dropdownLinkId.'][values][0][label]"]', 'Standard Support')
                    ->assertMissing('input[name="option_links['.$dropdownLinkId.'][unit_pricing][monthly]"]');
            });

            // The dropdown snapshot is stale (the group gained Enterprise
            // Support after attach): one click on "Sync values from group"
            // refreshes the product copy from the catalog.
            $browser->within('.option-link-card:has(input[name="option_links['.$dropdownLinkId.'][values][0][label]"])', function (Browser $card) use ($dropdownLinkId) {
                $card->press('Sync values from group');
            })->waitForDialog()->acceptDialog()
                ->waitForText('Option values synced from the group.')
                ->click('#edit-tab-options');

            $browser->within('.option-link-card:has(input[name="option_links['.$dropdownLinkId.'][values][0][label]"])', function (Browser $card) use ($dropdownLinkId) {
                // Value labels render as input values, not text.
                $card->assertValue('input[name="option_links['.$dropdownLinkId.'][values][2][label]"]', 'Enterprise Support');
            });

            // Change the monthly unit price, then save everything — product
            // plus all option links — with the header Save Changes button.
            // The update stays on the edit page (no redirect away).
            $browser->type('input[name="option_links['.$sliderLinkId.'][unit_pricing][monthly]"]', '125')
                ->press('Save Changes')
                ->waitForLocation('/admin/products/'.$this->product->id.'/edit');
        });

        $this->assertDatabaseHas('product_option_link_pricing', [
            'product_option_group_product_id' => $this->link->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 125.00,
        ]);

        $this->assertDatabaseHas('product_option_link_values', [
            'product_option_group_product_id' => $this->dropdownLink->id,
            'label' => 'Enterprise Support',
        ]);
    }

    public function test_admin_can_change_the_default_option_value(): void
    {
        // Regression for "not able to change the default value": the edit
        // form used to send a stale hidden default_value_id that overrode the
        // radio's selection on save. The Default radios are now the single
        // designator — checking Priority Support must move the default.
        $dropdownLinkId = $this->dropdownLink->id;
        $priorityValueId = $this->dropdownLink->linkValues()
            ->where('label', 'Priority Support')->firstOrFail()->id;

        $this->browse(function (Browser $browser) use ($dropdownLinkId, $priorityValueId) {
            $browser->visit('/login')
                ->type('email', $this->admin->email)
                ->type('password', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin/dashboard');

            $browser->visit('/admin/products/'.$this->product->id.'/edit')
                ->click('#edit-tab-options')
                ->waitForText('Control Panel')
                ->within('.option-link-card:has(input[name="option_links['.$dropdownLinkId.'][values][0][label]"])', function (Browser $card) use ($dropdownLinkId, $priorityValueId) {
                    $card->radio('#default-'.$dropdownLinkId.'-'.$priorityValueId, '1');
                })
                ->press('Save Changes')
                ->waitForLocation('/admin/products/'.$this->product->id.'/edit');
        });

        $this->assertSame(
            'Priority Support',
            $this->dropdownLink->linkValues()->where('is_default', true)->firstOrFail()->label,
            'The checked Default radio must move the single default on save.'
        );
    }

    public function test_admin_can_create_product_and_configure_unit_priced_options(): void
    {
        $product = null;
        $sliderLink = null;
        $dropdownLink = null;

        $this->browse(function (Browser $browser) use (&$product, &$sliderLink, &$dropdownLink) {
            $browser->visit('/login')
                ->type('email', $this->admin->email)
                ->type('password', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin/dashboard');

            // The create page itself has no option section (option groups
            // attach to an existing product), so this covers the full
            // lifecycle: create the product through the browser, then attach
            // both group types to it and configure unit pricing.
            $browser->visit('/admin/products/create')
                ->waitForText('Add Product')
                ->type('input[name="name"]', 'Managed VPS')
                ->select('product_group_id', (string) $this->category->id)
                ->click('#create-tab-pricing')
                ->type('input[name="pricing[monthly][price]"]', '499')
                ->press('Save Product')
                ->waitForText('Managed VPS');

            $product = Product::where('name', 'Managed VPS')->latest('id')->firstOrFail();

            // Attach the continuous slider group, then the discrete dropdown
            // group, through the attach picker on the edit page (Options tab;
            // the picker reloads after each attach).
            $sliderGroup = ProductOptionGroup::where('name', 'CPU Cores')->latest('id')->firstOrFail();
            $dropdownGroup = ProductOptionGroup::where('name', 'Control Panel')->latest('id')->firstOrFail();

            $browser->visit('/admin/products/'.$product->id.'/edit')
                ->click('#edit-tab-options')
                ->select('#option-group-select', (string) $sliderGroup->id)
                ->press('Attach')
                ->waitUntil('document.querySelector(\'input[name*="[unit_pricing][monthly]"]\') !== null')
                ->click('#edit-tab-options');

            $sliderLink = ProductOptionGroupProduct::where('product_id', $product->id)
                ->whereHas('group', fn ($query) => $query->where('name', 'CPU Cores'))
                ->firstOrFail();

            $browser->select('#option-group-select', (string) $dropdownGroup->id)
                ->press('Attach')
                ->waitUntil('document.querySelector(\'input[name*="[values][0][label]"]\') !== null')
                ->click('#edit-tab-options');

            $dropdownLink = ProductOptionGroupProduct::where('product_id', $product->id)
                ->whereHas('group', fn ($query) => $query->where('name', 'Control Panel'))
                ->firstOrFail();

            // Type-aware split on the newly created product: the continuous
            // card shows the unit-price field (no values table)...
            $browser->within('.option-link-card:has(input[name="option_links['.$sliderLink->id.'][unit_pricing][monthly]"])', function (Browser $card) use ($sliderLink) {
                $card->assertPresent('input[name="option_links['.$sliderLink->id.'][unit_pricing][monthly]"]')
                    ->assertMissing('input[name="option_links['.$sliderLink->id.'][values][0][label]"]');
            });

            // ...and the discrete card shows the values copied from the group
            // via attach (no unit pricing).
            $browser->within('.option-link-card:has(input[name="option_links['.$dropdownLink->id.'][values][0][label]"])', function (Browser $card) use ($dropdownLink) {
                $card->assertPresent('input[name="option_links['.$dropdownLink->id.'][values][0][label]"]')
                    ->assertValue('input[name="option_links['.$dropdownLink->id.'][values][0][label]"]', 'Standard Support')
                    ->assertMissing('input[name="option_links['.$dropdownLink->id.'][unit_pricing][monthly]"]');
            });

            // Set a unit price on the continuous card, then save everything —
            // product plus both option links — with the header Save Changes
            // button; the update stays on the edit page.
            $browser->type('input[name="option_links['.$sliderLink->id.'][unit_pricing][monthly]"]', '50')
                ->press('Save Changes')
                ->waitForLocation('/admin/products/'.$product->id.'/edit');
        });

        // The created product, its unit pricing and the copied values persisted.
        $this->assertDatabaseHas('products', [
            'name' => 'Managed VPS',
            'price' => 499.00,
        ]);

        $this->assertDatabaseHas('product_option_link_pricing', [
            'product_option_group_product_id' => $sliderLink->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 50.00,
        ]);

        $this->assertDatabaseHas('product_option_link_values', [
            'product_option_group_product_id' => $dropdownLink->id,
            'label' => 'Priority Support',
        ]);
    }

    public function test_admin_create_page_renders_type_aware_sections_and_saves_options_at_creation(): void
    {
        $sliderGroup = ProductOptionGroup::where('name', 'CPU Cores')->latest('id')->firstOrFail();
        $dropdownGroup = ProductOptionGroup::where('name', 'Control Panel')->latest('id')->firstOrFail();
        $priorityValueId = ProductOptionValue::where('option_group_id', $dropdownGroup->id)
            ->where('label', 'Priority Support')
            ->value('id');

        $this->browse(function (Browser $browser) use ($sliderGroup, $dropdownGroup, $priorityValueId) {
            $browser->visit('/login')
                ->type('email', $this->admin->email)
                ->type('password', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin/dashboard');

            // The create page renders both type-aware sections up front: the
            // continuous block is a per-cycle unit-price grid, the discrete
            // block a per-value pricing table.
            $browser->visit('/admin/products/create')
                ->waitForText('Add Product')
                ->click('#create-tab-options')
                ->within('.option-link-card:has(input[name="option_groups['.$sliderGroup->id.'][unit_pricing][monthly]"])', function (Browser $card) {
                    $card->assertPresent('input[name^="option_groups["][name*="[unit_pricing]"]')
                        ->assertMissing('input[name^="option_groups["][name*="[pricing]"]');
                })
                ->within('.option-link-card:has(input[name="option_groups['.$dropdownGroup->id.'][pricing]['.$priorityValueId.'][monthly]"])', function (Browser $card) {
                    $card->assertPresent('input[name^="option_groups["][name*="[pricing]"]')
                        ->assertMissing('input[name^="option_groups["][name*="[unit_pricing]"]');
                });

            // Attach each group through the picker (mirroring the edit page),
            // which unlocks its type-aware section; one submit then saves the
            // product with its options: unit pricing for the continuous group,
            // per-value pricing for the discrete group.
            $browser->select('#option-group-picker', (string) $sliderGroup->id)
                ->click('#option-group-attach-btn')
                ->select('#option-group-picker', (string) $dropdownGroup->id)
                ->click('#option-group-attach-btn');

            // The override block displays the group's Min/Max/Step (disabled),
            // and flips to per-product fields when toggled on.
            $browser->assertValue('input[name="option_groups['.$sliderGroup->id.'][input_min]"]', '1.00')
                ->assertValue('input[name="option_groups['.$sliderGroup->id.'][input_max]"]', '32.00')
                ->assertDisabled('input[name="option_groups['.$sliderGroup->id.'][input_min]"]')
                ->check('#create-override-'.$sliderGroup->id)
                ->assertEnabled('input[name="option_groups['.$sliderGroup->id.'][input_min]"]')
                ->type('input[name="option_groups['.$sliderGroup->id.'][input_min]"]', '2')
                ->type('input[name="option_groups['.$sliderGroup->id.'][input_max]"]', '16')
                ->type('input[name="option_groups['.$sliderGroup->id.'][unit_pricing][monthly]"]', '75')
                ->type('input[name="option_groups['.$dropdownGroup->id.'][pricing]['.$priorityValueId.'][monthly]"]', '199');

            // Switch tabs via JS click — the tab bar sits under the fixed
            // navbar, so a plain click is intercepted.
            $browser->script("document.getElementById('create-tab-details').click();");
            $browser->type('input[name="name"]', 'Dusk Managed')
                ->select('product_group_id', (string) $this->category->id);

            $browser->script("document.getElementById('create-tab-pricing').click();");
            $browser->type('input[name="pricing[monthly][price]"]', '299')
                ->press('Save Product')
                ->waitForText('Dusk Managed');
        });

        $product = Product::where('name', 'Dusk Managed')->latest('id')->firstOrFail();

        $sliderLink = ProductOptionGroupProduct::where('product_id', $product->id)
            ->whereHas('group', fn ($query) => $query->where('name', 'CPU Cores'))
            ->firstOrFail();
        $dropdownLink = ProductOptionGroupProduct::where('product_id', $product->id)
            ->whereHas('group', fn ($query) => $query->where('name', 'Control Panel'))
            ->firstOrFail();

        $this->assertDatabaseHas('product_option_link_pricing', [
            'product_option_group_product_id' => $sliderLink->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 75.00,
        ]);

        $priorityLinkValue = ProductOptionLinkValue::where('product_option_group_product_id', $dropdownLink->id)
            ->where('label', 'Priority Support')
            ->firstOrFail();
        $standardLinkValue = ProductOptionLinkValue::where('product_option_group_product_id', $dropdownLink->id)
            ->where('label', 'Standard Support')
            ->firstOrFail();

        $this->assertDatabaseHas('product_option_link_value_pricing', [
            'product_option_link_value_id' => $priorityLinkValue->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 199.00,
        ]);

        // The untouched Standard Support value keeps its copied price.
        $this->assertDatabaseHas('product_option_link_value_pricing', [
            'product_option_link_value_id' => $standardLinkValue->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 0.00,
        ]);

        // The per-product Min/Max/Step override persisted (Step was pre-filled
        // with the group's value and submitted with the toggle on); the
        // Placeholder was left blank and stays inherited (null).
        $sliderLink->refresh();
        $this->assertSame('2.00', $sliderLink->input_min);
        $this->assertSame('16.00', $sliderLink->input_max);
        $this->assertSame('1.00', $sliderLink->input_step);
        $this->assertNull($sliderLink->input_placeholder);
    }
}
