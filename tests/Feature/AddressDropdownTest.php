<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Datacenter;
use App\Models\User;
use App\Support\Countries;
use App\Support\GstStateCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Country and state are now dropdowns, fed from one list instead of the
 * ten-country array that had been pasted into six views.
 *
 * These assert against RENDERED HTML rather than the compiled template,
 * because a Blade file can compile without error and still emit the wrong
 * thing. City and postcode stay free text on purpose and are asserted as such:
 * this application ships no city list, and a partial one would refuse valid
 * addresses.
 */
class AddressDropdownTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────────── the lists ─────────────────────────────

    public function test_the_country_list_is_no_longer_the_old_nine_plus_other(): void
    {
        $html = $this->admin()->get(route('admin.customers.create'))->assertOk()->getContent();

        // Countries a real customer might be in that the old array had no room
        // for — their only option was the literal string "Other".
        foreach (['Brazil', 'Japan', 'Kenya', 'Nepal', 'Bangladesh', 'Sri Lanka'] as $country) {
            $this->assertStringContainsString('<option value="'.$country.'"', $html, "{$country} should be selectable.");
        }

        $this->assertStringNotContainsString('<option value="Other"', $html);
        $this->assertGreaterThan(150, count(Countries::names()));
    }

    public function test_india_renders_a_state_dropdown_of_the_states_that_currently_exist(): void
    {
        $html = $this->admin()->get(route('admin.customers.create'))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="Maharashtra"', $html);
        $this->assertStringContainsString('<option value="West Bengal"', $html);
        $this->assertStringContainsString('<option value="Ladakh"', $html);
    }

    public function test_the_two_historical_gst_codes_are_not_offered_as_an_address(): void
    {
        // 25 and 28 exist in CODES only so old GSTINs normalize. Nobody lives in
        // "Andhra Pradesh (before division)", and offering it invites a wrong
        // return.
        $current = GstStateCodes::current();

        $this->assertCount(36, $current);
        $this->assertArrayNotHasKey('25', $current);
        $this->assertArrayNotHasKey('28', $current);
        $this->assertCount(38, GstStateCodes::all(), 'all() must still carry both, for normalize().');

        $html = $this->admin()->get(route('admin.customers.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('before division', $html);
        $this->assertStringNotContainsString('merged into 26', $html);
    }

    // ───────────────────── not silently rewriting rows ─────────────────────

    public function test_a_stored_country_outside_the_list_is_kept_as_its_own_option(): void
    {
        // Legacy rows hold the literal "Other" that the old list wrote. A select
        // whose value is absent shows its FIRST option, so simply opening and
        // saving the customer would relocate them to Afghanistan.
        $customer = $this->customer(['country' => 'India']);
        $customer->user->forceFill(['country' => 'Other'])->save();

        $html = $this->admin()->get(route('admin.customers.edit', $customer))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="Other" selected>Other</option>', $html);
    }

    public function test_an_unrecognised_state_survives_being_opened_in_the_dropdown(): void
    {
        // A typo from the free-text era. Showing it lets the admin see and fix
        // it; dropping it would blank the column on the next save.
        $customer = $this->customer(['state' => 'West Bengal']);
        $customer->user->forceFill(['state' => 'Maharastra'])->save();

        $html = $this->admin()->get(route('admin.customers.edit', $customer))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="Maharastra" selected>Maharastra</option>', $html);
    }

    // ────────────────────────── one posted value ──────────────────────────

    public function test_only_one_control_carries_the_field_name(): void
    {
        // The select and its text twin are both in the DOM; only the hidden
        // input has a name, so exactly one value is ever posted no matter which
        // one is on screen.
        $html = $this->admin()->get(route('admin.customers.create'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="state"'));
        $this->assertSame(1, substr_count($html, 'name="country"'));
    }

    public function test_a_country_without_a_subdivision_list_renders_the_text_box_enabled(): void
    {
        $customer = $this->customer(['country' => 'India']);
        $customer->user->forceFill(['country' => 'Germany', 'state' => 'Bavaria'])->save();

        $html = $this->admin()->get(route('admin.customers.edit', $customer))->assertOk()->getContent();

        // Germany has no list here, so the dropdown is the disabled twin.
        $this->assertMatchesRegularExpression('/<select[^>]*data-state-select[^>]*\shidden\s+disabled/', $html);
        $this->assertStringContainsString('value="Bavaria"', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*data-state-text[^>]*\shidden\s+disabled/', $html);
    }

    // ──────────────────────── the reason it matters ────────────────────────

    public function test_every_state_the_dropdown_offers_resolves_to_a_gst_code(): void
    {
        // The point of the control. A free-text state that fails to normalize
        // leaves customers.state_code NULL, which GstTaxService reads as
        // inter-state — and with igst_rate 0 that is a zero-tax invoice.
        foreach (GstStateCodes::currentNames() as $name) {
            $this->assertNotNull(
                GstStateCodes::normalize($name),
                "'{$name}' is offered in the dropdown but does not normalize.",
            );
        }
    }

    public function test_a_state_picked_from_the_dropdown_reaches_the_customer_state_code(): void
    {
        $customer = $this->customer(['state' => 'West Bengal']);

        $this->assertSame('19', $customer->fresh()->state_code);
    }

    // ─────────────────────── city stays free text ───────────────────────

    /**
     * City and postcode stay free text on EVERY address form. This is a
     * decision, not an omission, so it is asserted everywhere rather than on
     * one representative page.
     *
     * Why: nothing branches on `city` — unlike `state`, which feeds
     * GstStateCodes::normalize() and therefore the CGST/SGST-vs-IGST test, so a
     * wrong state produces a wrong invoice while a misspelled city produces an
     * ugly one. And every candidate list fails: a curated one refuses customers
     * from towns it omits, a full import is ~150k rows nothing reads, and a
     * datalist of cities already in the database would publish our customer
     * geography on the unauthenticated /register page.
     *
     * Postcode additionally has no single format to constrain to — alphanumeric
     * in the UK and Canada, numeric in India and the US.
     */
    public function test_city_and_postcode_are_free_text_on_every_address_form(): void
    {
        $customer = $this->customer();
        $user = User::factory()->create(['role' => 'admin']);
        $datacenter = Datacenter::create(['name' => 'Chennai DC-1', 'code' => 'MAA1', 'status' => 'active']);

        $urls = [
            route('admin.customers.create'),
            route('admin.customers.edit', $customer),
            route('admin.users.create'),
            route('admin.users.edit', $user),
            route('admin.datacenters.create'),
            route('admin.datacenters.edit', $datacenter),
        ];

        foreach ($urls as $url) {
            $html = $this->admin()->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<input[^>]*name="city"/', $html, "{$url}: city must stay a text input.");
            $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="city"/', $html, "{$url}: city must not become a dropdown.");
        }

        // The settings company address uses its own prefixed field names.
        $settings = $this->admin()->get(route('admin.settings.index', ['tab' => 'general']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*name="settings\[company_city\]"/', $settings);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="settings\[company_city\]"/', $settings);

        // Postcode, where the form has one (datacenters do not).
        foreach ([route('admin.customers.create'), route('admin.users.create'), route('register')] as $url) {
            $html = $url === route('register')
                ? $this->get($url)->assertOk()->getContent()
                : $this->admin()->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<input[^>]*name="postcode"/', $html, "{$url}: postcode must stay a text input.");
            $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="postcode"/', $html, "{$url}: postcode must not become a dropdown.");
        }
    }

    // ────────────────────────────── autofill ──────────────────────────────

    /**
     * Admin forms must NOT ask the browser to autofill an address.
     *
     * The semantic tokens (street-address, address-level2, …) mean "this is the
     * address of the person filling the form in". On an admin form that person
     * is staff entering somebody else's address, so the browser offers the
     * admin's own saved address — one stray tab-complete from writing their
     * home address onto a customer record, and from there onto that customer's
     * invoice. admin/orders/create shipped with exactly those tokens.
     */
    public function test_admin_address_forms_do_not_invite_browser_autofill(): void
    {
        $customer = $this->customer();
        $user = User::factory()->create(['role' => 'admin']);
        $datacenter = Datacenter::create(['name' => 'Pune DC-1', 'code' => 'PNQ1', 'status' => 'active']);

        $urls = [
            route('admin.customers.create'),
            route('admin.customers.edit', $customer),
            route('admin.users.create'),
            route('admin.users.edit', $user),
            route('admin.datacenters.create'),
            route('admin.datacenters.edit', $datacenter),
            route('admin.orders.create'),
            route('admin.settings.index', ['tab' => 'general']),
        ];

        foreach ($urls as $url) {
            $html = $this->admin()->get($url)->assertOk()->getContent();

            foreach (['street-address', 'address-line2', 'address-level1', 'address-level2', 'postal-code', 'country-name'] as $token) {
                $this->assertStringNotContainsString(
                    'autocomplete="'.$token.'"',
                    $html,
                    "{$url} still asks the browser to autofill an address ({$token}).",
                );
            }
        }
    }

    /**
     * The mirror image: on the two forms where people edit their OWN address,
     * the semantic tokens are correct and must stay.
     */
    public function test_client_facing_forms_keep_the_real_address_tokens(): void
    {
        $customer = $this->customer();

        $pages = [
            route('register') => $this->get(route('register'))->assertOk()->getContent(),
            route('client.profile') => $this->actingAs($customer->user)->get(route('client.profile'))->assertOk()->getContent(),
        ];

        foreach ($pages as $url => $html) {
            foreach (['street-address', 'address-line2', 'address-level1', 'address-level2', 'postal-code', 'country-name'] as $token) {
                $this->assertStringContainsString(
                    'autocomplete="'.$token.'"',
                    $html,
                    "{$url} lost the {$token} token; this form holds the user's own address.",
                );
            }
        }
    }

    // ───────────────────────────── datacenters ─────────────────────────────

    public function test_the_datacenter_country_is_a_dropdown_that_does_not_invent_a_default(): void
    {
        // It was free text until now, so "no country recorded" is a real state
        // here: defaulting it to India would write a country into every row an
        // admin opened for an unrelated reason.
        $datacenter = Datacenter::create(['name' => 'Frankfurt DC-1', 'code' => 'FRA1', 'status' => 'active']);

        $html = $this->admin()->get(route('admin.datacenters.edit', $datacenter))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select[^>]*name="country"/', $html);
        $this->assertStringContainsString('<option value="" selected>', $html);
    }

    // ─────────────────────── the other five forms ───────────────────────

    /**
     * Every form that carries an address renders both controls. Listed
     * explicitly rather than globbed so that a new address form has to be added
     * here consciously.
     */
    public function test_all_the_address_forms_render_the_shared_controls(): void
    {
        $customer = $this->customer();
        $user = User::factory()->create(['role' => 'admin']);
        $datacenter = Datacenter::create(['name' => 'Mumbai DC-1', 'code' => 'BOM1', 'status' => 'active']);

        $urls = [
            route('admin.customers.create'),
            route('admin.customers.edit', $customer),
            route('admin.users.create'),
            route('admin.users.edit', $user),
            route('admin.datacenters.create'),
            route('admin.datacenters.edit', $datacenter),
            route('admin.settings.index', ['tab' => 'general']),
        ];

        foreach ($urls as $url) {
            $html = $this->admin()->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-state-field', $html, "{$url} lost its state control.");
            $this->assertStringContainsString('<option value="Maharashtra"', $html, "{$url} lost its state list.");
            $this->assertStringContainsString('<option value="Brazil"', $html, "{$url} lost its country list.");
        }
    }

    public function test_the_client_facing_forms_render_them_too(): void
    {
        $customer = $this->customer();

        foreach ([route('register'), route('client.profile')] as $url) {
            $html = $url === route('register')
                ? $this->get($url)->assertOk()->getContent()
                : $this->actingAs($customer->user)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-state-field', $html, "{$url} lost its state control.");
            $this->assertStringContainsString('<option value="Brazil"', $html, "{$url} lost its country list.");
        }
    }

    public function test_the_settings_state_field_keeps_the_id_the_invoice_preview_reads(): void
    {
        // The company-address preview script looks the field up by id and
        // listens for `input`; the component re-dispatches on the hidden field,
        // so the id has to stay on it.
        $html = $this->admin()->get(route('admin.settings.index', ['tab' => 'general']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<input type="hidden" name="settings\[company_state\]" id="company_state"/',
            $html,
        );
        $this->assertStringContainsString('id="company_country"', $html);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /**
     * A customer created through the admin form, so state_code is derived the
     * way the application derives it rather than written by the test.
     */
    private function customer(array $overrides = []): Customer
    {
        $email = 'c'.uniqid().'@example.test';

        $this->admin()->post(route('admin.customers.store'), array_replace([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => $email,
            'password' => 'Password!234',
            'password_confirmation' => 'Password!234',
            'city' => 'Kolkata',
            'state' => 'West Bengal',
            'postcode' => '700001',
            'country' => 'India',
            'status' => 'active',
        ], $overrides))->assertSessionHasNoErrors();

        return Customer::whereHas('user', fn ($q) => $q->where('email', $email))->sole();
    }

    private function admin(): self
    {
        $user = User::query()->where('email', 'address-dropdown-admin@example.test')->first()
            ?? User::factory()->create(['role' => 'admin', 'email' => 'address-dropdown-admin@example.test']);

        return $this->actingAs($user);
    }
}
