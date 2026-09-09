<?php

namespace Tests\Dusk;

use App\Models\Permission;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionPricing;
use App\Models\ProductOptionValue;
use App\Models\Role;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Browser coverage for the option-template form's three interactions, none of
 * which a server-rendered assertion can reach:
 *
 *  - the per-value "Different price on other cycles" COLLAPSE,
 *  - the ▲ / ▼ reorder buttons (and the sort_order they renumber),
 *  - the type-driven disclosure that hides the blocks a type does not use.
 *
 * These are the parts of the redesign that only exist client-side, so they are
 * exactly the parts a feature test cannot confirm.
 *
 * Runtime contract matches ConfigurableOptionPreviewTest: the app must be
 * served with the Dusk environment (`php artisan serve --env=dusk` on the
 * APP_URL in .env.dusk.local) so the browser and the test process share
 * database/dusk.sqlite. The schema is migrated fresh once per process and this
 * test removes only its own fixtures.
 */
class OptionGroupFormInteractionTest extends DuskTestCase
{
    private static bool $schemaMigrated = false;

    private ProductOptionGroup $group;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schemaMigrated) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            self::$schemaMigrated = true;
        }

        $this->removeFixtureRows();

        // A discrete group with three values, the middle one priced on a
        // non-monthly cycle so the collapse must start OPEN for that row.
        $this->group = ProductOptionGroup::create([
            'name' => 'Dusk Backup',
            'sort_order' => 1,
            'type' => 'dropdown',
            'input_min' => 1,
            'input_max' => 32,
            'input_step' => 1,
        ]);

        foreach ([['Daily', 'monthly', 199.0], ['Weekly', 'annual', 990.0], ['None', 'monthly', 0.0]] as $index => [$label, $cycle, $modifier]) {
            $value = ProductOptionValue::create([
                'option_group_id' => $this->group->id,
                'label' => $label,
                'is_default' => $index === 0,
                'sort_order' => $index,
            ]);

            ProductOptionPricing::create([
                'option_value_id' => $value->id,
                'billing_cycle' => $cycle,
                'price_modifier' => $modifier,
            ]);
        }

        $this->admin = User::factory()->create([
            'email' => 'dusk-options-admin@example.com',
            'role' => 'admin',
        ]);
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['dashboard.view', 'products.view', 'products.options'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->admin->assignRole('admin');
    }

    private function removeFixtureRows(): void
    {
        $groupIds = ProductOptionGroup::where('name', 'Dusk Backup')->pluck('id');
        $valueIds = ProductOptionValue::whereIn('option_group_id', $groupIds)->pluck('id');
        ProductOptionPricing::whereIn('option_value_id', $valueIds)->delete();
        ProductOptionValue::whereIn('id', $valueIds)->delete();
        ProductOptionGroup::whereIn('id', $groupIds)->delete();

        User::where('email', 'dusk-options-admin@example.com')->delete();
    }

    public function test_the_other_cycles_panel_collapses_and_expands_per_value(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/product-options/'.$this->group->id.'/edit')
                ->waitForText('Option values');

            // Row 0 (Daily) is priced monthly only, so its extra-cycle panel
            // starts collapsed. assertMissing is Dusk's "not visible" — the
            // input is in the DOM (it still posts) but hidden.
            $browser->assertMissing('#value-cycles-0 input[name="values[0][pricing][annual][price_modifier]"]');

            // Row 1 (Weekly) carries an ANNUAL price, which the monthly box
            // cannot express — the panel opens itself so the override is never
            // hidden behind a collapsed toggle.
            $browser->assertVisible('#value-cycles-1 input[name="values[1][pricing][annual][price_modifier]"]')
                ->assertValue('input[name="values[1][pricing][annual][price_modifier]"]', '990.00')
                ->assertSeeIn('#value-cycles-1', 'Blank uses the monthly price');

            // Clicking the toggle expands row 0 ...
            $browser->click('button[data-bs-target="#value-cycles-0"]')
                ->waitFor('#value-cycles-0.show')
                ->assertVisible('#value-cycles-0 input[name="values[0][pricing][annual][price_modifier]"]');

            // ... and clicking it again puts it away.
            $browser->click('button[data-bs-target="#value-cycles-0"]')
                ->waitUntilMissing('#value-cycles-0.show')
                ->assertMissing('#value-cycles-0 input[name="values[0][pricing][annual][price_modifier]"]');
        });
    }

    public function test_the_reorder_buttons_move_a_row_and_renumber_sort_order(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/product-options/'.$this->group->id.'/edit')
                ->waitForText('Option values');

            $labels = fn (Browser $b) => $b->script(
                "return Array.from(document.querySelectorAll('.option-value-row input[name$=\"[label]\"]')).map(function (i) { return i.value; });"
            )[0];

            $sortOrders = fn (Browser $b) => $b->script(
                "return Array.from(document.querySelectorAll('.value-sort-order')).map(function (i) { return i.value; });"
            )[0];

            $this->assertSame(['Daily', 'Weekly', 'None'], $labels($browser));

            // ▼ on the first row swaps it with the second.
            $browser->click('.option-value-row:nth-of-type(1) .move-value-down')
                ->pause(200);

            $this->assertSame(['Weekly', 'Daily', 'None'], $labels($browser), 'The ▼ button moved Daily down one place.');
            $this->assertSame(['0', '1', '2'], $sortOrders($browser), 'sort_order is renumbered from the row order, so the admin never types it.');

            // ▲ on the last row lifts it above the middle one.
            $browser->click('.option-value-row:nth-of-type(3) .move-value-up')
                ->pause(200);

            $this->assertSame(['Weekly', 'None', 'Daily'], $labels($browser), 'The ▲ button moved None up one place.');
            $this->assertSame(['0', '1', '2'], $sortOrders($browser));

            // The buttons are inert at the ends rather than wrapping around.
            $browser->click('.option-value-row:nth-of-type(1) .move-value-up')
                ->pause(200);
            $this->assertSame(['Weekly', 'None', 'Daily'], $labels($browser), '▲ on the first row does nothing.');

            $browser->click('.option-value-row:nth-of-type(3) .move-value-down')
                ->pause(200);
            $this->assertSame(['Weekly', 'None', 'Daily'], $labels($browser), '▼ on the last row does nothing.');
        });
    }

    public function test_a_reordered_list_saves_in_the_order_shown(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/product-options/'.$this->group->id.'/edit')
                ->waitForText('Option values')
                ->click('.option-value-row:nth-of-type(1) .move-value-down')
                ->pause(200)
                ->press('Save Changes')
                ->waitForText('updated');
        });

        $this->assertSame(
            ['Weekly', 'Daily', 'None'],
            $this->group->values()->orderBy('sort_order')->orderBy('id')->pluck('label')->all(),
            'The order the admin dragged the rows into is the order that persisted.'
        );
    }

    public function test_adding_and_removing_a_value_keeps_exactly_one_default(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/product-options/'.$this->group->id.'/edit')
                ->waitForText('Option values');

            $checkedDefaults = fn (Browser $b) => (int) $b->script(
                "return document.querySelectorAll('.default-value-radio:checked').length;"
            )[0];

            $this->assertSame(1, $checkedDefaults($browser));

            // Adding a row must not leave the list with two defaults.
            $browser->click('#add-option-value')->pause(200);
            $this->assertSame(1, $checkedDefaults($browser));

            // Removing the row that HELD the default re-homes it rather than
            // leaving the list with none.
            $browser->click('.option-value-row:nth-of-type(1) .remove-option-value')->pause(200);
            $this->assertSame(1, $checkedDefaults($browser), 'Removing the default row promotes another, never leaves zero.');
        });
    }

    public function test_the_type_selector_shows_only_the_fields_that_type_uses(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin)
                ->visit('/admin/product-options/'.$this->group->id.'/edit')
                ->waitForText('Option values');

            // Dropdown: values, no Min/Max/Step, no maximum-selections.
            // assertMissing is Dusk's "not visible" — these blocks stay in the
            // DOM (hiding is cosmetic) but must not be on screen.
            $browser->assertVisible('[data-option-field="values"]')
                ->assertMissing('[data-option-field="range"]')
                ->assertMissing('[data-option-field="max-selections"]');

            // Slider: priced per unit on the product, so the value list goes
            // away and the numeric bounds appear.
            $browser->select('#option-type-select', 'slider')
                ->pause(200)
                ->assertMissing('[data-option-field="values"]')
                ->assertVisible('[data-option-field="range"]')
                ->assertVisible('[data-option-field="unit-price-note"]')
                ->assertSee('priced');

            // Checkbox: the value list returns and input_max becomes a cap on
            // how many may be ticked.
            $browser->select('#option-type-select', 'checkbox')
                ->pause(200)
                ->assertVisible('[data-option-field="values"]')
                ->assertVisible('[data-option-field="max-selections"]')
                ->assertMissing('[data-option-field="range"]');

            // Text carries no price at all.
            $browser->select('#option-type-select', 'text')
                ->pause(200)
                ->assertMissing('[data-option-field="values"]')
                ->assertVisible('[data-option-field="text-note"]');

            // Hiding is cosmetic: the hidden value inputs still hold their
            // data, so flipping the type to look and flipping back cannot
            // silently empty the list.
            $stillThere = $browser->script(
                "return document.querySelector('input[name=\"values[0][label]\"]').value;"
            )[0];
            $this->assertSame('Daily', $stillThere, 'A hidden value row keeps its data and still posts.');
        });
    }

    public function test_the_numeric_bounds_render_as_plain_numbers(): void
    {
        $this->browse(function (Browser $browser) {
            // decimal:2 stringified a whole bound as "32.00"; the admin typed
            // 32 and was shown something else back.
            $browser->loginAs($this->admin)
                ->visit('/admin/product-options/'.$this->group->id.'/edit')
                ->waitForText('Option values')
                ->select('#option-type-select', 'slider')
                ->pause(200)
                ->assertValue('input[name="input_min"]', '1')
                ->assertValue('input[name="input_max"]', '32')
                ->assertValue('input[name="input_step"]', '1');
        });
    }
}
