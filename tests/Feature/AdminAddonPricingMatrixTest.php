<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\ProductAddon;
use App\Models\ProductAddonPricing;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F1 admin UI: the per-cycle pricing matrix on the add-on create/edit forms
 * (`pricing_cycles[]` + `pricing[<cycle>][price|setup_fee]`) and its
 * replace-all persistence as `product_addon_pricing` rows.
 */
class AdminAddonPricingMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAddonAdmin(): User
    {
        $user = User::factory()->create();

        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $permission = Permission::firstOrCreate(['name' => 'products.addons'], ['label' => 'Products Addons']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user->assignRole('admin');

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Daily Backup',
            'description' => 'Nightly off-site backup',
            'billing_cycle' => 'monthly',
            'price' => '100.00',
            'setup_fee' => '0.00',
            'status' => 'active',
        ], $overrides);
    }

    private function makeAddon(): ProductAddon
    {
        return ProductAddon::create([
            'name' => 'Daily Backup',
            'billing_cycle' => 'monthly',
            'price' => 100.00,
            'setup_fee' => 0.00,
            'status' => 'active',
        ]);
    }

    public function test_store_creates_one_matrix_row_per_checked_cycle(): void
    {
        $response = $this->actingAs($this->actingAsAddonAdmin())
            ->post(route('admin.addons.store'), $this->payload([
                'pricing_cycles' => ['quarterly', 'annual'],
                'pricing' => [
                    'quarterly' => ['price' => '250.00', 'setup_fee' => '10.00'],
                    'annual' => ['price' => '900.00', 'setup_fee' => '0.00'],
                ],
            ]));

        $response->assertRedirect(route('admin.addons.index'));

        $addon = ProductAddon::query()->firstWhere('name', 'Daily Backup');
        $this->assertNotNull($addon);

        $rows = $addon->pricing()->get()->keyBy('billing_cycle');

        $this->assertCount(2, $rows);
        $this->assertSame('250.00', $rows['quarterly']->price);
        $this->assertSame('10.00', $rows['quarterly']->setup_fee);
        $this->assertSame('900.00', $rows['annual']->price);
        $this->assertSame('0.00', $rows['annual']->setup_fee);
    }

    public function test_store_without_checked_cycles_creates_no_matrix_rows(): void
    {
        $response = $this->actingAs($this->actingAsAddonAdmin())
            ->post(route('admin.addons.store'), $this->payload());

        $response->assertRedirect(route('admin.addons.index'));

        $addon = ProductAddon::query()->firstWhere('name', 'Daily Backup');
        $this->assertNotNull($addon);
        $this->assertSame(0, $addon->pricing()->count());
    }

    public function test_update_replaces_the_whole_matrix(): void
    {
        $admin = $this->actingAsAddonAdmin();
        $addon = $this->makeAddon();

        ProductAddonPricing::create([
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
            'price' => 50.00,
            'setup_fee' => 0.00,
        ]);

        $response = $this->actingAs($admin)->put(route('admin.addons.update', $addon), $this->payload([
            'pricing_cycles' => ['annual'],
            'pricing' => [
                'annual' => ['price' => '800.00', 'setup_fee' => '25.00'],
            ],
        ]));

        $response->assertRedirect(route('admin.addons.index'));

        $rows = $addon->pricing()->get();

        $this->assertCount(1, $rows);
        $this->assertSame('annual', $rows[0]->billing_cycle);
        $this->assertSame('800.00', $rows[0]->price);
        $this->assertSame('25.00', $rows[0]->setup_fee);
        $this->assertDatabaseMissing('product_addon_pricing', [
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'monthly',
        ]);
    }

    public function test_store_rejects_a_non_numeric_matrix_price(): void
    {
        $response = $this->actingAs($this->actingAsAddonAdmin())
            ->post(route('admin.addons.store'), $this->payload([
                'pricing_cycles' => ['monthly'],
                'pricing' => [
                    'monthly' => ['price' => 'not-a-number', 'setup_fee' => '0.00'],
                ],
            ]));

        $response->assertSessionHasErrors('pricing.0.price');
        $this->assertDatabaseMissing('product_addons', ['name' => 'Daily Backup']);
        $this->assertSame(0, ProductAddonPricing::query()->count());
    }

    public function test_store_rejects_an_unknown_cycle(): void
    {
        $response = $this->actingAs($this->actingAsAddonAdmin())
            ->post(route('admin.addons.store'), $this->payload([
                'pricing_cycles' => ['fortnightly'],
                'pricing' => [
                    'fortnightly' => ['price' => '10.00', 'setup_fee' => '0.00'],
                ],
            ]));

        $response->assertSessionHasErrors('pricing.0.billing_cycle');
        $this->assertDatabaseMissing('product_addons', ['name' => 'Daily Backup']);
    }

    public function test_store_rejects_a_cycle_submitted_twice(): void
    {
        $response = $this->actingAs($this->actingAsAddonAdmin())
            ->post(route('admin.addons.store'), $this->payload([
                'pricing_cycles' => ['monthly', 'monthly'],
                'pricing' => [
                    'monthly' => ['price' => '10.00', 'setup_fee' => '0.00'],
                ],
            ]));

        $response->assertSessionHasErrors('pricing.1.billing_cycle');
        $this->assertDatabaseMissing('product_addons', ['name' => 'Daily Backup']);
    }

    public function test_create_page_renders_a_matrix_row_for_every_billing_cycle(): void
    {
        $response = $this->actingAs($this->actingAsAddonAdmin())
            ->get(route('admin.addons.create'));

        $response->assertOk();

        foreach (Order::BILLING_CYCLES as $cycle) {
            $response->assertSee('id="pricing-cycle-'.$cycle.'"', false);
            $response->assertSee('name="pricing['.$cycle.'][price]"', false);
            $response->assertSee('name="pricing['.$cycle.'][setup_fee]"', false);
        }

        // A fresh form enables no cycle.
        $this->assertDoesNotMatchRegularExpression(
            '/name="pricing_cycles\[\]"[^>]*checked/',
            $response->getContent()
        );
    }

    public function test_edit_page_prefills_and_checks_stored_matrix_rows(): void
    {
        $admin = $this->actingAsAddonAdmin();
        $addon = $this->makeAddon();

        ProductAddonPricing::create([
            'product_addon_id' => $addon->id,
            'billing_cycle' => 'annual',
            'price' => 800.00,
            'setup_fee' => 25.00,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.addons.edit', $addon));

        $response->assertOk();

        $content = $response->getContent();

        $this->assertMatchesRegularExpression('/id="pricing-cycle-annual"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/id="pricing-cycle-monthly"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/name="pricing\[annual\]\[price\]"[^>]*value="800\.00"/', $content);
        $this->assertMatchesRegularExpression('/name="pricing\[annual\]\[setup_fee\]"[^>]*value="25\.00"/', $content);
    }

    public function test_edit_page_honours_old_input_after_a_failed_update(): void
    {
        $admin = $this->actingAsAddonAdmin();
        $addon = $this->makeAddon();

        $page = $this->actingAs($admin)
            ->followingRedirects()
            ->from(route('admin.addons.edit', $addon))
            ->put(route('admin.addons.update', $addon), $this->payload([
                'name' => '',
                'pricing_cycles' => ['annual'],
                'pricing' => [
                    'annual' => ['price' => '777.00', 'setup_fee' => '0.00'],
                ],
            ]));

        $page->assertOk();

        $content = $page->getContent();

        $this->assertMatchesRegularExpression('/id="pricing-cycle-annual"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/name="pricing\[annual\]\[price\]"[^>]*value="777\.00"/', $content);
    }
}
