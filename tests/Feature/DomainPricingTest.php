<?php

namespace Tests\Feature;

use App\Models\DomainPricing;
use App\Models\DomainPricingTerm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainPricingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin()
    {
        $user = User::factory()->create();

        // Seed the admin role and domains.manage permission in test DB
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $perm = Permission::firstOrCreate(['name' => 'domains.manage'], ['label' => 'Manage Domains']);
        $adminRole->permissions()->syncWithoutDetaching($perm->id);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    public function test_admin_creates_pricing_row(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/domain-pricing', [
            'tld' => 'com',
            'register_price' => 999.00,
            'renew_price' => 899.00,
            'transfer_price' => 799.00,
            'currency' => 'INR',
            'premium' => false,
            'enabled' => true,
        ]);

        $response->assertRedirect(route('admin.domain-pricing.index'));

        $this->assertDatabaseHas('domain_pricing', [
            'tld' => 'com',
            'register_price' => 999.00,
            'renew_price' => 899.00,
            'transfer_price' => 799.00,
        ]);

        // Verify row visible on index
        $indexResponse = $this->actingAsAdmin()->get('/admin/domain-pricing');
        $indexResponse->assertSee('com');
    }

    public function test_admin_edits_pricing_row(): void
    {
        $pricing = DomainPricing::create([
            'tld' => 'net',
            'register_price' => 1200.00,
            'renew_price' => 1100.00,
            'transfer_price' => 1000.00,
        ]);

        $response = $this->actingAsAdmin()->put("/admin/domain-pricing/{$pricing->id}", [
            'tld' => 'net',
            'register_price' => 1200.00,
            'renew_price' => 1500.00,
            'transfer_price' => 1000.00,
        ]);

        $response->assertRedirect(route('admin.domain-pricing.show', $pricing));

        $this->assertDatabaseHas('domain_pricing', [
            'id' => $pricing->id,
            'renew_price' => 1500.00,
        ]);
    }

    public function test_admin_can_add_term_rows(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/domain-pricing', [
            'tld' => 'org',
            'register_price' => 500.00,
            'renew_price' => 450.00,
            'transfer_price' => 400.00,
            'terms' => [
                ['term_years' => 1, 'register_price' => 500.00, 'renew_price' => 450.00],
                ['term_years' => 2, 'register_price' => 900.00, 'renew_price' => 800.00],
                ['term_years' => 3, 'register_price' => 1200.00, 'renew_price' => 1100.00],
            ],
        ]);

        $response->assertRedirect(route('admin.domain-pricing.index'));

        $pricing = DomainPricing::where('tld', 'org')->first();
        $this->assertNotNull($pricing);
        $this->assertDatabaseCount('domain_pricing_terms', 3);
        $this->assertDatabaseHas('domain_pricing_terms', [
            'domain_pricing_id' => $pricing->id,
            'term_years' => 2,
            'register_price' => 900.00,
        ]);
    }

    public function test_duplicate_tld_rejected(): void
    {
        DomainPricing::create([
            'tld' => 'com',
            'register_price' => 999.00,
            'renew_price' => 899.00,
            'transfer_price' => 799.00,
        ]);

        $response = $this->actingAsAdmin()->post('/admin/domain-pricing', [
            'tld' => 'com',
            'register_price' => 999.00,
            'renew_price' => 899.00,
            'transfer_price' => 799.00,
        ]);

        $response->assertSessionHasErrors('tld');
    }

    public function test_destroy_removes_pricing_and_terms(): void
    {
        $pricing = DomainPricing::create([
            'tld' => 'info',
            'register_price' => 300.00,
            'renew_price' => 280.00,
            'transfer_price' => 260.00,
        ]);

        DomainPricingTerm::create([
            'domain_pricing_id' => $pricing->id,
            'term_years' => 1,
            'register_price' => 300.00,
            'renew_price' => 280.00,
        ]);

        $response = $this->actingAsAdmin()->delete("/admin/domain-pricing/{$pricing->id}");

        $response->assertRedirect(route('admin.domain-pricing.index'));
        $this->assertDatabaseMissing('domain_pricing', ['id' => $pricing->id]);
        $this->assertDatabaseCount('domain_pricing_terms', 0);
    }
}
