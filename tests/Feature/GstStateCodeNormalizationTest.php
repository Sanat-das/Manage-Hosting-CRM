<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GstSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\GstTaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Both state-code columns now speak the same language, so the intra-state test
 * can actually succeed.
 *
 * It could not before: `gst_settings.state_code` held '27' and
 * `customers.state_code` held 'WB', and GstTaxService::isIntraState() compared
 * them as raw strings. Every customer was therefore inter-state, and with an
 * IGST rate of 0 every invoice came out untaxed.
 */
class GstStateCodeNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_in_the_company_state_is_now_intra_state(): void
    {
        $this->gst(['state_code' => '27']);
        $customer = $this->customer('27');

        $code = app(BillingService::class)->resolveCustomerStateCode($customer->id);

        $this->assertSame('27', $code);
        $this->assertTrue(GstTaxService::isIntraState('27', $code));
    }

    public function test_a_legacy_alpha_code_still_resolves_instead_of_silently_going_inter_state(): void
    {
        // A row an older release wrote, or an import: the migration normalizes
        // stored data, and resolveCustomerStateCode() normalizes on read so a
        // straggler cannot quietly cost the customer their CGST+SGST treatment.
        $this->gst(['state_code' => '27']);
        $customer = $this->customer('27');
        DB::table('customers')->where('id', $customer->id)->update(['state_code' => 'MH']);

        $code = app(BillingService::class)->resolveCustomerStateCode($customer->id);

        $this->assertSame('27', $code);
        $this->assertTrue(GstTaxService::isIntraState('27', $code));
    }

    public function test_the_pairing_that_used_to_be_broken(): void
    {
        // The exact live combination: company '27', customer 'WB'. It must come
        // out INTER-state (they really are different states) but for the right
        // reason — a resolved 19 vs 27, not two uncomparable notations.
        $this->gst(['state_code' => '27']);
        $customer = $this->customer('West Bengal');

        $code = app(BillingService::class)->resolveCustomerStateCode($customer->id);

        $this->assertSame('19', $code);
        $this->assertFalse(GstTaxService::isIntraState('27', $code));
        $this->assertTrue(GstTaxService::isIntraState('19', $code));
    }

    public function test_an_unplaceable_state_is_null_not_a_two_letter_guess(): void
    {
        $customer = $this->customer('Bengaluru');

        $this->assertNull($customer->fresh()->state_code);
        $this->assertNull(app(BillingService::class)->resolveCustomerStateCode($customer->id));
    }

    public function test_intra_state_holds_whichever_notation_each_side_uses(): void
    {
        foreach ([['27', 'MH'], ['MH', '27'], ['Maharashtra', '27'], ['27', 'maharashtra']] as [$company, $customer]) {
            $this->assertTrue(
                GstTaxService::isIntraState($company, $customer),
                "isIntraState('{$company}', '{$customer}') should match.",
            );
        }
    }

    public function test_the_gst_form_rejects_a_state_code_outside_the_thirty_eight(): void
    {
        $this->gst();

        $this->admin()
            ->put(route('admin.gst-settings.update'), $this->payload(['state_code' => 'MH']))
            ->assertSessionHasErrors('state_code');

        $this->admin()
            ->put(route('admin.gst-settings.update'), $this->payload(['state_code' => '99']))
            ->assertSessionHasErrors('state_code');

        $this->assertSame('27', GstSetting::first()->state_code);
    }

    public function test_saving_a_valid_code_derives_the_state_name(): void
    {
        $this->gst();

        $this->admin()
            ->put(route('admin.gst-settings.update'), $this->payload(['state_code' => '19']))
            ->assertSessionHasNoErrors();

        $gst = GstSetting::first();
        $this->assertSame('19', $gst->state_code);
        // Never posted — derived, so the code and the name cannot disagree.
        $this->assertSame('West Bengal', $gst->state_name);
    }

    public function test_the_migration_converts_what_is_already_stored(): void
    {
        $this->gst(['state_code' => '27']);

        $wb = $this->customer('West Bengal');
        $unknown = $this->customer('Bengaluru');

        // Put the columns back into the pre-migration state by hand.
        DB::table('customers')->where('id', $wb->id)->update(['state_code' => 'WB']);
        DB::table('customers')->where('id', $unknown->id)->update(['state_code' => 'BE']);
        DB::table('gst_settings')->update(['state_code' => 'MH', 'state_name' => 'Maharshtra']);

        require_once database_path('migrations/2026_09_09_120000_normalize_state_codes_to_gst_codes.php');
        (require database_path('migrations/2026_09_09_120000_normalize_state_codes_to_gst_codes.php'))->up();

        $this->assertSame('19', DB::table('customers')->where('id', $wb->id)->value('state_code'));
        // 'BE' resolves to nothing and the address says Bengaluru, which is a
        // city — nulled rather than guessed at.
        $this->assertNull(DB::table('customers')->where('id', $unknown->id)->value('state_code'));

        $gst = DB::table('gst_settings')->first();
        $this->assertSame('27', $gst->state_code);
        $this->assertSame('Maharashtra', $gst->state_name);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    private function gst(array $overrides = []): GstSetting
    {
        $gst = GstSetting::firstOrNew([]);

        $gst->fill(array_replace([
            'gstin' => '27AAAAA0000A1Z5',
            'legal_name' => 'Acme Hosting Pvt Ltd',
            'state_code' => '27',
            'state_name' => 'Maharashtra',
            'cgst_rate' => 9.00,
            'sgst_rate' => 9.00,
            'igst_rate' => 18.00,
            'enabled' => true,
            'tax_mode' => 'global',
        ], $overrides))->save();

        return $gst;
    }

    /**
     * A customer created through the admin form, so the state code is derived
     * the way the application derives it rather than written by the test.
     */
    private function customer(string $state): Customer
    {
        $email = 'c'.uniqid().'@example.test';

        $this->admin()->post(route('admin.customers.store'), [
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => $email,
            'password' => 'Password!234',
            'password_confirmation' => 'Password!234',
            'state' => $state,
            'country' => 'India',
            'status' => 'active',
        ]);

        return Customer::whereHas('user', fn ($q) => $q->where('email', $email))->sole();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'gstin' => '27AAAAA0000A1Z5',
            'legal_name' => 'Acme Hosting Pvt Ltd',
            'state_code' => '27',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'tax_mode' => 'global',
            'enabled' => 1,
        ], $overrides);
    }

    private function admin(): self
    {
        static $user = null;

        if ($user === null) {
            $user = User::factory()->create(['role' => 'admin']);

            $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

            foreach (['settings.view', 'settings.manage', 'settings.edit', 'customers.view', 'customers.create', 'customers.edit'] as $name) {
                $permission = Permission::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
                $role->permissions()->syncWithoutDetaching($permission->id);
            }

            $user->assignRole('admin');
        }

        return $this->actingAs($user);
    }
}
