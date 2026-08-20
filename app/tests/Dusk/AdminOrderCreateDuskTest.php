<?php

namespace Tests\Dusk;

use App\Models\Customer;
use App\Models\GstSetting;
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
 * Browser-level coverage for the enhanced admin New Order page:
 * the line-items editor (add/remove product lines with per-line cycles,
 * quantities and prices), per-line configurable-option controls, the
 * GST-aware total preview, and the inline customer creation modal.
 *
 * Same runtime contract as ConfigurableOptionPreviewTest: the app must be
 * served with `php artisan serve --env=dusk` so the browser and the test
 * process share database/dusk.sqlite. The schema is migrated fresh once per
 * process; setUp deletes only this test's fixtures by stable identifiers.
 */
class AdminOrderCreateDuskTest extends DuskTestCase
{
    private static bool $schemaMigrated = false;

    private User $admin;

    private Product $vps;

    private Product $backup;

    private ProductOptionGroupProduct $supportLink;

    private ProductOptionGroupProduct $sliderLink;

    private ProductOptionGroupProduct $capLink;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schemaMigrated) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            self::$schemaMigrated = true;
        }

        $this->removeFixtureRows();

        // GST engine enabled (global mode, 18% IGST) so the order form's
        // total preview computes a real tax estimate. The migration seeds the
        // id=1 row (enabled=0), so update it in place — GstSetting::create
        // cannot set the id column (it is not fillable).
        GstSetting::where('id', 1)->update([
            'state_code' => '27',
            'state_name' => 'Maharashtra',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'enabled' => 1,
            'tax_mode' => GstSetting::TAX_MODE_GLOBAL,
        ]);

        $category = ProductGroup::create([
            'name' => 'Order Cloud',
            'slug' => 'order-cloud',
            'status' => 'active',
            'is_hosting' => true,
        ]);

        // Primary order product with an editable dropdown option group.
        $this->vps = Product::create([
            'name' => 'Order VPS',
            'product_group_id' => $category->id,
            'price' => 199.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
            'require_domain' => false,
        ]);

        foreach ([['monthly', 199.00], ['annual', 1990.00]] as [$cycle, $price]) {
            ProductPricing::create([
                'product_id' => $this->vps->id,
                'billing_cycle' => $cycle,
                'price' => $price,
                'setup_fee' => 0,
            ]);
        }

        $group = ProductOptionGroup::create([
            'name' => 'Support Level',
            'sort_order' => 1,
            'type' => 'dropdown',
        ]);

        $this->supportLink = ProductOptionGroupProduct::create([
            'product_id' => $this->vps->id,
            'option_group_id' => $group->id,
            'customer_editable' => true,
        ]);

        foreach ([['Standard Support', 0.0], ['Priority Support', 149.0]] as $index => [$label, $modifier]) {
            $value = ProductOptionValue::create([
                'option_group_id' => $group->id,
                'label' => $label,
                'sort_order' => $index + 1,
            ]);

            ProductOptionPricing::create([
                'option_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $modifier,
            ]);

            $linkValue = ProductOptionLinkValue::create([
                'product_option_group_product_id' => $this->supportLink->id,
                'label' => $label,
                'is_default' => $index === 0,
                'sort_order' => $index + 1,
            ]);

            ProductOptionLinkValuePricing::create([
                'product_option_link_value_id' => $linkValue->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $modifier,
            ]);
        }

        // Informational (non-editable) option link — must render display-only
        // AND never charge: its values carry price modifiers that the order
        // form must ignore.
        $infoGroup = ProductOptionGroup::create([
            'name' => 'Datacenter',
            'sort_order' => 2,
            'type' => 'dropdown',
        ]);

        $infoLink = ProductOptionGroupProduct::create([
            'product_id' => $this->vps->id,
            'option_group_id' => $infoGroup->id,
            'customer_editable' => false,
        ]);

        foreach ([['Mumbai', 100.0], ['Delhi', 200.0]] as $index => [$label, $modifier]) {
            $value = ProductOptionValue::create([
                'option_group_id' => $infoGroup->id,
                'label' => $label,
                'sort_order' => $index + 1,
            ]);

            ProductOptionPricing::create([
                'option_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $modifier,
            ]);

            $linkValue = ProductOptionLinkValue::create([
                'product_option_group_product_id' => $infoLink->id,
                'label' => $label,
                'is_default' => $index === 0,
                'sort_order' => $index + 1,
            ]);

            ProductOptionLinkValuePricing::create([
                'product_option_link_value_id' => $linkValue->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => $modifier,
            ]);
        }

        // Continuous (slider) option link with a per-unit price — value ×
        // ₹50.00 must display and charge.
        $sliderGroup = ProductOptionGroup::create([
            'name' => 'CPU Cores',
            'sort_order' => 3,
            'type' => 'slider',
            'input_min' => 0,
            'input_max' => 8,
            'input_step' => 1,
        ]);

        $sliderLink = ProductOptionGroupProduct::create([
            'product_id' => $this->vps->id,
            'option_group_id' => $sliderGroup->id,
            'customer_editable' => true,
        ]);

        ProductOptionLinkPricing::create([
            'product_option_group_product_id' => $sliderLink->id,
            'billing_cycle' => 'monthly',
            'price_modifier' => 50.00,
        ]);

        // Capped checkbox group (max 2 of 3) — used by the re-render test.
        $capGroup = ProductOptionGroup::create([
            'name' => 'Extras',
            'sort_order' => 4,
            'type' => 'checkbox',
            'input_max' => 2,
        ]);

        $this->capLink = ProductOptionGroupProduct::create([
            'product_id' => $this->vps->id,
            'option_group_id' => $capGroup->id,
            'customer_editable' => true,
        ]);

        foreach (['Backup', 'SSL', 'DDoS'] as $index => $label) {
            $value = ProductOptionValue::create([
                'option_group_id' => $capGroup->id,
                'label' => $label,
                'sort_order' => $index + 1,
            ]);

            ProductOptionPricing::create([
                'option_value_id' => $value->id,
                'billing_cycle' => 'monthly',
                'price_modifier' => 0.0,
            ]);

            ProductOptionLinkValue::create([
                'product_option_group_product_id' => $this->capLink->id,
                'label' => $label,
                'is_default' => false,
                'sort_order' => $index + 1,
            ]);
        }

        $this->sliderLink = $sliderLink;

        // Second product for the "Add another product" flow.
        $this->backup = Product::create([
            'name' => 'Order Backup',
            'product_group_id' => $category->id,
            'price' => 49.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
            'require_domain' => false,
        ]);

        ProductPricing::create([
            'product_id' => $this->backup->id,
            'billing_cycle' => 'monthly',
            'price' => 49.00,
            'setup_fee' => 0,
        ]);

        $this->admin = User::factory()->create([
            'email' => 'dusk-order-admin@example.com',
            'role' => 'admin',
        ]);

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['dashboard.view', 'orders.view', 'orders.create', 'orders.edit', 'customers.create'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->admin->assignRole('admin');

        // An orderable customer for the form (the other tests create theirs
        // inline via the modal).
        $clientUser = User::factory()->create([
            'email' => 'dusk-order-customer@example.com',
            'role' => 'client',
        ]);
        $this->customer = Customer::create([
            'user_id' => $clientUser->id,
            'company' => 'Order Test Corp',
            'status' => 'active',
        ]);
    }

    /**
     * Remove this test's fixtures by their stable identifiers (see the class
     * docblock for why the schema is never torn down between tests).
     */
    private function removeFixtureRows(): void
    {
        $userIds = User::whereIn('email', [
            'dusk-order-admin@example.com',
            'dusk-inline@example.com',
            'dusk-modal-close@example.com',
            'dusk-order-customer@example.com',
        ])->pluck('id');
        Customer::whereIn('user_id', $userIds)->delete();
        User::whereIn('id', $userIds)->delete();

        $productIds = Product::whereIn('name', ['Order VPS', 'Order Backup'])->pluck('id');
        Order::whereIn('product_id', $productIds)->delete();
        Product::whereIn('id', $productIds)->delete();

        ProductOptionGroup::whereIn('name', ['Support Level', 'Datacenter', 'CPU Cores', 'Extras'])->delete();
        ProductGroup::where('name', 'Order Cloud')->delete();

        // Restore the migration-seeded GST row to its default (disabled).
        GstSetting::where('id', 1)->update(['enabled' => 0, 'tax_mode' => 'global']);
    }

    private function loginAdmin(Browser $browser): Browser
    {
        return $browser->visit('/login')
            ->type('email', $this->admin->email)
            ->type('password', 'password')
            ->press('button[type="submit"]')
            ->waitForLocation('/admin/dashboard');
    }

    public function test_admin_new_order_line_editor_updates_gst_total_preview(): void
    {
        $vpsId = $this->vps->id;
        $backupId = $this->backup->id;

        $this->browse(function (Browser $browser) use ($vpsId, $backupId) {
            $this->loginAdmin($browser);

            $browser->visit('/admin/orders/create')
                ->waitFor('#order-lines')
                ->assertPresent('#order-lines .order-line')
                // The estimate label shows the effective GST rate (global mode,
                // no customer selected yet → inter-state IGST 18%).
                ->assertSeeIn('#gst-label', 'GST (estimate @ 18%)');

            // Line 1: pick Order VPS. Dusk's select() clicks the option without
            // always firing the change event the form JS listens for, so set
            // the value and dispatch change explicitly.
            $browser->script("
                const productSelect = document.querySelector('#order-lines .order-line:nth-of-type(1) .line-product');
                productSelect.value = '" . $vpsId . "';
                productSelect.dispatchEvent(new Event('change', { bubbles: true }));
            ");

            $browser->waitUntil('document.querySelector("#order-lines .order-line .line-price") !== null && document.querySelector("#order-lines .order-line .line-price").value === "199.00"')
                ->within('#order-lines .order-line:nth-of-type(1)', function (Browser $line) {
                    $line->assertInputValue('.line-price', '199.00')
                        ->assertSelected('.line-cycle', 'monthly')
                        ->assertNotSelected('.line-cycle', 'quarterly');
                });

            // Qty 2 → subtotal 398.00, GST 18% = 71.64, total 469.64.
            $browser->within('#order-lines .order-line:nth-of-type(1)', function (Browser $line) {
                $line->type('.line-qty', '2');
            });

            $browser->waitUntil('document.getElementById("gst-total").textContent === "₹469.64"')
                ->assertSeeIn('#gst-subtotal', '₹398.00')
                ->assertSeeIn('#gst-amount', '₹71.64');

            // Add a second line and pick Order Backup — its own price joins
            // the preview: 447.00 + 80.46 GST = 527.46.
            $browser->click('#add-line-btn')
                ->waitUntil('document.querySelectorAll("#order-lines .order-line").length === 2')
                ->script("
                    const backupSelect = document.querySelector('#order-lines .order-line:nth-of-type(2) .line-product');
                    backupSelect.value = '" . $backupId . "';
                    backupSelect.dispatchEvent(new Event('change', { bubbles: true }));
                ");

            $browser->waitUntil('document.getElementById("gst-total").textContent === "₹527.46"')
                ->assertSeeIn('#gst-subtotal', '₹447.00')
                ->assertSeeIn('#gst-amount', '₹80.46');

            // The second line can be removed again (single-line minimum).
            $browser->within('#order-lines .order-line:nth-of-type(2)', function (Browser $line) {
                $line->click('.line-remove');
            });

            $browser->waitUntil('document.querySelectorAll("#order-lines .order-line").length === 1')
                ->assertSeeIn('#gst-subtotal', '₹398.00');
        });
    }

    public function test_admin_new_order_renders_informational_options_display_only(): void
    {
        // Gap 1 regression: informational (non-editable) option links render as
        // static text, NOT as editable controls — no select is submitted for
        // them, their price modifiers are never shown, and they never charge.
        $vpsId = $this->vps->id;
        $infoLinkId = $this->vps->optionLinks()
            ->whereHas('group', fn ($query) => $query->where('name', 'Datacenter'))
            ->firstOrFail()->id;

        $this->browse(function (Browser $browser) use ($vpsId, $infoLinkId) {
            $this->loginAdmin($browser);

            $browser->visit('/admin/orders/create')
                ->waitFor('#order-lines')
                ->script("
                    const productSelect = document.querySelector('#order-lines .order-line:nth-of-type(1) .line-product');
                    productSelect.value = '" . $vpsId . "';
                    productSelect.dispatchEvent(new Event('change', { bubbles: true }));
                ");

            $browser->waitUntil('document.querySelector("#order-lines .line-options") !== null')
                ->waitUntil('document.querySelectorAll("#order-lines .line-options select").length === 1')
                ->assertSee('Mumbai, Delhi') // informational values shown as plain text
                ->assertDontSee('Mumbai (+₹100.00') // no modifier label on informational values
                ->assertMissing('select[name="lines[0][options][' . $infoLinkId . ']"]')
                // The Datacenter values carry +₹100/+₹200 modifiers, yet the
                // preview stays at the base price (199 + 18% GST = 234.82).
                ->waitUntil('document.getElementById("gst-total").textContent === "₹234.82"')
                ->assertSeeIn('#gst-subtotal', '₹199.00')
                ->assertSeeIn('#gst-amount', '₹35.82');
        });
    }

    public function test_admin_new_order_continuous_option_price_displays_and_charges(): void
    {
        // A continuous (slider) option shows its per-unit price and charges
        // value × unit price: 3 cores × ₹50.00 = +₹150.00 on the ₹199.00 base.
        $vpsId = $this->vps->id;

        $this->browse(function (Browser $browser) use ($vpsId) {
            $this->loginAdmin($browser);

            $browser->visit('/admin/orders/create')
                ->waitFor('#order-lines')
                ->script("
                    const productSelect = document.querySelector('#order-lines .order-line:nth-of-type(1) .line-product');
                    productSelect.value = '" . $vpsId . "';
                    productSelect.dispatchEvent(new Event('change', { bubbles: true }));
                ");

            // The slider renders with its per-unit price; defaults add nothing.
            $browser->waitUntil('document.querySelector("#order-lines .line-options input[type=\"range\"]") !== null')
                ->assertSee('₹50.00 per unit / mo')
                ->waitUntil('document.getElementById("gst-total").textContent === "₹234.82"')
                ->assertSeeIn('#gst-subtotal', '₹199.00');

            // Move the slider to 3 → 3 × ₹50 = ₹150 adjustment.
            $browser->script("
                const range = document.querySelector('#order-lines .line-options input[type=\"range\"]');
                range.value = '3';
                range.dispatchEvent(new Event('input', { bubbles: true }));
                range.dispatchEvent(new Event('change', { bubbles: true }));
            ");

            $browser->waitUntil('document.getElementById("gst-total").textContent === "₹411.82"')
                ->assertSeeIn('#gst-subtotal', '₹349.00')
                ->assertSeeIn('#gst-amount', '₹62.82')
                ->assertSeeIn('.line-adjustment', 'Options +₹150.00 / mo');
        });
    }

    public function test_admin_order_form_cap_applies_to_restored_prechecked_options(): void
    {
        // The app has no order-edit page — the closest edit path is the create
        // form's validation-error re-render, which restores the submitted
        // lines from old(). Pre-checked (restored) checkbox selections must sit
        // within the cap and grey out the remaining options immediately.
        $vpsId = $this->vps->id;
        $capLinkId = $this->capLink->id;

        $this->browse(function (Browser $browser) use ($vpsId, $capLinkId) {
            $this->loginAdmin($browser);

            $browser->visit('/admin/orders/create')
                ->waitFor('#order-lines')
                ->script("
                    const productSelect = document.querySelector('#order-lines .order-line:nth-of-type(1) .line-product');
                    productSelect.value = '" . $vpsId . "';
                    productSelect.dispatchEvent(new Event('change', { bubbles: true }));
                ");

            $browser->waitUntil('document.querySelectorAll("#order-lines .line-options input[type=\"checkbox\"]").length === 3')
                ->select('#customer_id', (string) $this->customer->id);

            // Check two of the three options (at the cap), then submit a price
            // that fails the catalog-price guard to force a validation-error
            // re-render.
            $browser->script("
                const boxes = document.querySelectorAll('#order-lines .line-options input[name=\"lines[0][options][" . $capLinkId . "][]\"]');
                boxes[0].checked = true; boxes[1].checked = true;
                boxes[0].dispatchEvent(new Event('change', { bubbles: true }));
                boxes[1].dispatchEvent(new Event('change', { bubbles: true }));
            ");

            $browser->type('input[name="lines[0][unit_price]"]', '300')
                ->press('Create Order')
                ->waitForText('does not match the catalog price');

            // Per-field validation styling: the offending price input carries
            // is-invalid and an inline feedback message.
            $browser->waitUntil('document.querySelector("input[name=\"lines[0][unit_price]\"]").classList.contains("is-invalid")')
                ->assertSeeIn('#order-lines', 'does not match the catalog price');

            // The selections survive the round-trip (pre-checked) and the cap
            // applies to them immediately: Backup + SSL restored, DDoS blocked.
            $state = $browser->script("
                return Array.from(document.querySelectorAll('#order-lines .line-options input[name=\"lines[0][options][" . $capLinkId . "][]\"]')).map(function (cb) {
                    return cb.value + '=' + cb.checked + '=' + cb.disabled;
                });
            ");
            $this->assertSame(['Backup=true=false', 'SSL=true=false', 'DDoS=false=true'], $state[0], 'Pre-checked options restore within the cap; the rest grey out.');

            // The blocked option carries the grey-out class.
            $this->assertTrue(
                $browser->script("return document.querySelectorAll('#order-lines .line-options [data-checkbox-option]')[2].classList.contains('option-cap-limited');")[0]
            );

            // Unchecking one drops below the cap and re-enables the third.
            $browser->script("
                const boxes = document.querySelectorAll('#order-lines .line-options input[name=\"lines[0][options][" . $capLinkId . "][]\"]');
                boxes[0].checked = false;
                boxes[0].dispatchEvent(new Event('change', { bubbles: true }));
            ");
            $this->assertFalse(
                $browser->script("return document.querySelectorAll('#order-lines .line-options input[name=\"lines[0][options][" . $capLinkId . "][]\"]')[2].disabled;")[0],
                'Dropping below the cap re-enables the blocked option.'
            );
        });
    }

    public function test_admin_new_order_captures_option_selection_and_inline_customer(): void
    {
        $vpsId = $this->vps->id;
        $linkId = $this->supportLink->id;
        $capLinkId = $this->capLink->id;

        $this->browse(function (Browser $browser) use ($vpsId, $linkId, $capLinkId) {
            $this->loginAdmin($browser);

            $browser->visit('/admin/orders/create')
                ->waitFor('#order-lines')
                ->script("
                    const productSelect = document.querySelector('#order-lines .order-line:nth-of-type(1) .line-product');
                    productSelect.value = '" . $vpsId . "';
                    productSelect.dispatchEvent(new Event('change', { bubbles: true }));
                ");

            // The product's configurable option control renders; pick a value.
            $browser->waitUntil('document.querySelector("#order-lines .line-options select") !== null')
                ->script("
                    const optionSelect = document.querySelector('#order-lines .line-options select[name=\"lines[0][options][" . $linkId . "]\"]');
                    optionSelect.value = 'Priority Support';
                    optionSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    // The required Extras checkbox group needs at least one pick.
                    const extras = document.querySelectorAll('#order-lines .line-options input[name=\"lines[0][options][" . $capLinkId . "][]\"]');
                    extras[0].checked = true;
                    extras[0].dispatchEvent(new Event('change', { bubbles: true }));
                ");

            $browser->within('#order-lines .order-line:nth-of-type(1)', function (Browser $line) use ($linkId) {
                $line->assertSelected('select[name="lines[0][options][' . $linkId . ']"]', 'Priority Support');
            });

            // The option carries its price info and moves the total: base
            // ₹199.00 + Priority Support (+₹149.00/mo) = ₹348.00, GST 18% =
            // ₹62.64 → ₹410.64.
            $browser->assertSeeIn('.line-adjustment', 'Options +₹149.00 / mo')
                ->waitUntil('document.getElementById("gst-total").textContent === "₹410.64"')
                ->assertSeeIn('#gst-subtotal', '₹348.00');

            // Inline customer: create one through the modal and let it select.
            $browser->click('#new-customer-btn')
                ->waitFor('#new-customer-modal.show')
                ->type('#qc-first_name', 'Dusk')
                ->type('#qc-last_name', 'Inline')
                ->type('#qc-email', 'dusk-inline@example.com')
                ->type('#qc-password', 'Password123')
                ->type('#qc-company', 'Dusk Corp')
                ->click('#customer-quick-save')
                ->waitUntil('document.getElementById("customer_id").selectedOptions[0].textContent.includes("Dusk Inline")')
                ->waitUntil('!document.getElementById("new-customer-modal").classList.contains("show")');

            // Place the order through the header button.
            $browser->press('Create Order')
                ->waitUntil('window.location.pathname.match(/^\/admin\/orders\/\d+$/) !== null');
        });

        // The order persisted with the primary product and the option choice.
        $order = Order::query()
            ->whereHas('customer.user', fn ($user) => $user->where('email', 'dusk-inline@example.com'))
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($this->vps->id, $order->product_id);
        $this->assertSame('348.00', (string) $order->total, 'The option modifier is charged: 199 + 149.');

        $item = $order->items()->firstOrFail();
        $this->assertSame('348.00', (string) $item->unit_price);
        $this->assertSame('Priority Support', $item->config_options['options'][0]['selected']);

        // The inline customer row exists.
        $this->assertDatabaseHas('customers', [
            'company' => 'Dusk Corp',
            'status' => 'active',
        ]);
    }

    public function test_inline_customer_modal_closes_after_creation_and_reopens(): void
    {
        // Regression: the modal close used to rely on the `bootstrap` global,
        // which AdminLTE does not expose (it loads Bootstrap as an ES module),
        // so the creation handler threw and the modal stayed open — blocking
        // the form's submit button. Closing now goes through the dismiss
        // button's delegated handler; this test pins the full close lifecycle.
        $this->browse(function (Browser $browser) {
            $this->loginAdmin($browser);

            $browser->visit('/admin/orders/create')
                ->waitFor('#order-lines');

            // Open the modal and create a customer through it.
            $browser->click('#new-customer-btn')
                ->waitFor('#new-customer-modal.show')
                ->type('#qc-first_name', 'Modal')
                ->type('#qc-last_name', 'Close')
                ->type('#qc-email', 'dusk-modal-close@example.com')
                ->type('#qc-password', 'Password123')
                ->click('#customer-quick-save')
                ->waitUntil('document.getElementById("customer_id").selectedOptions[0].textContent.includes("Modal Close")');

            // The dismiss-button close must tear the modal fully down: no
            // `.show` class, hidden, and no leftover backdrop.
            $browser->waitUntil('!document.getElementById("new-customer-modal").classList.contains("show")')
                ->waitUntil('document.getElementById("new-customer-modal").style.display === "none"')
                ->waitUntil('document.querySelectorAll(".modal-backdrop").length === 0');

            // The modal remains usable: it reopens on demand and the dismiss
            // button closes it again (the delegated-handler path).
            $browser->click('#new-customer-btn')
                ->waitFor('#new-customer-modal.show')
                ->click('#new-customer-modal .btn-close')
                ->waitUntil('!document.getElementById("new-customer-modal").classList.contains("show")')
                ->waitUntil('document.getElementById("new-customer-modal").style.display === "none"');
        });

        // The customer created through the modal persisted.
        $this->assertDatabaseHas('users', ['email' => 'dusk-modal-close@example.com']);
        $this->assertDatabaseHas('customers', [
            'company' => null,
            'status' => 'active',
        ]);
    }
}
