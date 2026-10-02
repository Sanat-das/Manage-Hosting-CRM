<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUpgradePath;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Upgrade Paths tab on the admin product edit page: a two-column
 * (upgrade / downgrade) manager over the product's product_upgrade_paths rows.
 * The tab's add/remove forms are separate from the single product update form,
 * so they must not interfere with it.
 */
class ProductUpgradePathsTabTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['products.view', 'products.edit'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    private function makeProduct(string $name = 'Shared Hosting Basic', array $overrides = []): Product
    {
        $product = Product::create(array_merge([
            'name' => $name,
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'provisioning_module' => 'manual',
            'show_in_order' => true,
            'status' => 'active',
        ], $overrides));

        $product->pricing()->create([
            'billing_cycle' => 'monthly',
            'price' => 100.00,
            'setup_fee' => 0,
        ]);

        return $product->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Business cPanel Hosting',
            'billing_cycle' => 'monthly',
            'payment_type' => 'recurring',
            'provisioning_module' => 'manual',
            'status' => 'active',
            'gst_type' => 'standard',
            'quantity_behaviour' => 'multiple_services',
            'sort_order' => 5,
            'require_domain' => '0',
            'show_in_order' => '1',
            'show_in_affiliate' => '0',
            'only_admin' => '0',
            'require_public_ip' => '0',
            'require_private_ip' => '0',
            'is_bundle' => '0',
            'pricing' => [
                'monthly' => ['price' => '499.00', 'setup_fee' => '0'],
            ],
        ], $overrides);
    }

    /**
     * The rendered <ul> for one direction column, so a path can be asserted to
     * be in the right list and absent from the other.
     */
    private function listHtml(string $html, string $direction): string
    {
        preg_match('/<ul[^>]*data-upgrade-list="'.$direction.'"[^>]*>(.*?)<\/ul>/s', $html, $matches);

        return $matches[1] ?? '';
    }

    // ─────────────────────────────── Render ───────────────────────────────

    public function test_tab_renders_with_two_columns(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();

        $this->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('id="edit-tab-upgrade-paths"', false)
            ->assertSee('id="edit-pane-upgrade-paths"', false)
            ->assertSee('Upgrade products')
            ->assertSee('Downgrade products')
            ->assertSee('No upgrade targets configured.')
            ->assertSee('No downgrade targets configured.');
    }

    public function test_active_tab_flash_opens_the_upgrade_paths_pane(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();

        $this->withSession(['active_tab' => 'upgrade-paths'])
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('class="tab-pane fade show active"', false)
            ->assertSee('id="edit-pane-upgrade-paths"', false);
    }

    // ─────────────────────────────── Add ───────────────────────────────

    public function test_add_creates_a_path_with_the_chosen_direction_in_the_right_column(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct('Hosting Basic');
        $target = $this->makeProduct('Hosting Pro');

        $this->from(route('admin.products.edit', $product))
            ->post(route('admin.products.upgrade-paths.store', $product), [
                'to_product_id' => $target->id,
                'direction' => 'upgrade',
            ])
            ->assertRedirect(route('admin.products.edit', $product))
            ->assertSessionHas('success')
            ->assertSessionHas('active_tab', 'upgrade-paths');

        $this->assertDatabaseHas('product_upgrade_paths', [
            'from_product_id' => $product->id,
            'to_product_id' => $target->id,
            'direction' => 'upgrade',
            'enabled' => true,
        ]);

        $html = $this->get(route('admin.products.edit', $product))->assertOk()->getContent();

        $this->assertStringContainsString('Hosting Pro', $this->listHtml($html, 'upgrade'));
        $this->assertStringNotContainsString('Hosting Pro', $this->listHtml($html, 'downgrade'));
    }

    public function test_add_accepts_each_direction(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct('Hosting Basic');
        $upgrade = $this->makeProduct('Hosting Pro');
        $downgrade = $this->makeProduct('Hosting Starter');

        foreach ([
            [$upgrade, 'upgrade'],
            [$downgrade, 'downgrade'],
        ] as [$target, $direction]) {
            $this->post(route('admin.products.upgrade-paths.store', $product), [
                'to_product_id' => $target->id,
                'direction' => $direction,
            ])->assertSessionHasNoErrors();
        }

        $this->assertDatabaseHas('product_upgrade_paths', ['to_product_id' => $upgrade->id, 'direction' => 'upgrade']);
        $this->assertDatabaseHas('product_upgrade_paths', ['to_product_id' => $downgrade->id, 'direction' => 'downgrade']);
    }

    public function test_add_rejects_both_from_the_tab(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct('Hosting Basic');
        $target = $this->makeProduct('Hosting Pro');

        $this->from(route('admin.products.edit', $product))
            ->post(route('admin.products.upgrade-paths.store', $product), [
                'to_product_id' => $target->id,
                'direction' => 'both',
            ])
            ->assertSessionHasErrors('direction')
            ->assertSessionHas('active_tab', 'upgrade-paths');

        $this->assertDatabaseMissing('product_upgrade_paths', ['from_product_id' => $product->id]);
    }

    public function test_add_rejects_an_invalid_direction(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct('Hosting Basic');
        $target = $this->makeProduct('Hosting Pro');

        $this->from(route('admin.products.edit', $product))
            ->post(route('admin.products.upgrade-paths.store', $product), [
                'to_product_id' => $target->id,
                'direction' => 'sideways',
            ])
            ->assertSessionHasErrors('direction')
            ->assertSessionHas('active_tab', 'upgrade-paths');

        $this->assertDatabaseMissing('product_upgrade_paths', ['from_product_id' => $product->id]);
    }

    public function test_add_rejects_the_product_itself_as_a_target(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct('Hosting Basic');

        $this->post(route('admin.products.upgrade-paths.store', $product), [
            'to_product_id' => $product->id,
            'direction' => 'upgrade',
        ])->assertSessionHasErrors('to_product_id');

        $this->assertDatabaseMissing('product_upgrade_paths', ['from_product_id' => $product->id]);
    }

    public function test_add_rejects_a_duplicate_path(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct('Hosting Basic');
        $target = $this->makeProduct('Hosting Pro');

        ProductUpgradePath::create([
            'from_product_id' => $product->id,
            'to_product_id' => $target->id,
            'direction' => 'upgrade',
            'enabled' => true,
        ]);

        $this->post(route('admin.products.upgrade-paths.store', $product), [
            'to_product_id' => $target->id,
            'direction' => 'upgrade',
        ])->assertSessionHasErrors('to_product_id');

        $this->assertSame(1, ProductUpgradePath::where('from_product_id', $product->id)->count());
    }

    // ─────────────────────────── Direction columns ───────────────────────────

    public function test_each_column_shows_only_its_direction(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct('Hosting Basic');
        $upgradeTarget = $this->makeProduct('Hosting Pro');
        $downgradeTarget = $this->makeProduct('Hosting Starter');
        $bothTarget = $this->makeProduct('Hosting Flex');

        ProductUpgradePath::create(['from_product_id' => $product->id, 'to_product_id' => $upgradeTarget->id, 'direction' => 'upgrade', 'enabled' => true]);
        ProductUpgradePath::create(['from_product_id' => $product->id, 'to_product_id' => $downgradeTarget->id, 'direction' => 'downgrade', 'enabled' => true]);
        ProductUpgradePath::create(['from_product_id' => $product->id, 'to_product_id' => $bothTarget->id, 'direction' => 'both', 'enabled' => true]);

        $html = $this->get(route('admin.products.edit', $product))->assertOk()->getContent();
        $upgradeList = $this->listHtml($html, 'upgrade');
        $downgradeList = $this->listHtml($html, 'downgrade');

        // Upgrade column: upgrade + both, never the downgrade-only target.
        $this->assertStringContainsString('Hosting Pro', $upgradeList);
        $this->assertStringContainsString('Hosting Flex', $upgradeList);
        $this->assertStringNotContainsString('Hosting Starter', $upgradeList);

        // Downgrade column: downgrade + both, never the upgrade-only target.
        $this->assertStringContainsString('Hosting Starter', $downgradeList);
        $this->assertStringContainsString('Hosting Flex', $downgradeList);
        $this->assertStringNotContainsString('Hosting Pro', $downgradeList);
    }

    // ─────────────────────────────── Remove ───────────────────────────────

    public function test_remove_deletes_the_path(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct('Hosting Basic');
        $target = $this->makeProduct('Hosting Pro');

        $path = ProductUpgradePath::create([
            'from_product_id' => $product->id,
            'to_product_id' => $target->id,
            'direction' => 'upgrade',
            'enabled' => true,
        ]);

        $this->from(route('admin.products.edit', $product))
            ->delete(route('admin.products.upgrade-paths.destroy', [$product, $path]))
            ->assertRedirect(route('admin.products.edit', $product))
            ->assertSessionHas('success')
            ->assertSessionHas('active_tab', 'upgrade-paths');

        $this->assertDatabaseMissing('product_upgrade_paths', ['id' => $path->id]);
    }

    public function test_remove_is_scoped_to_the_product_in_the_url(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct('Hosting Basic');
        $other = $this->makeProduct('Hosting Pro');
        $target = $this->makeProduct('Hosting Flex');

        $foreignPath = ProductUpgradePath::create([
            'from_product_id' => $other->id,
            'to_product_id' => $target->id,
            'direction' => 'upgrade',
            'enabled' => true,
        ]);

        $this->from(route('admin.products.edit', $product))
            ->delete(route('admin.products.upgrade-paths.destroy', [$product, $foreignPath]))
            ->assertNotFound();

        $this->assertDatabaseHas('product_upgrade_paths', ['id' => $foreignPath->id]);
    }

    // ─────────────────── Independence from the product form ───────────────────

    public function test_main_product_form_still_saves_independently(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct('Hosting Basic');
        $target = $this->makeProduct('Hosting Pro');

        $path = ProductUpgradePath::create([
            'from_product_id' => $product->id,
            'to_product_id' => $target->id,
            'direction' => 'both',
            'enabled' => true,
        ]);

        $this->from(route('admin.products.edit', $product))
            ->put(route('admin.products.update', $product), $this->productPayload([
                'name' => 'Hosting Basic Renamed',
                'active_tab' => 'details',
            ]))
            ->assertRedirect(route('admin.products.edit', $product))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertSame('Hosting Basic Renamed', $product->fresh()->name);

        // The upgrade path is untouched by the product save.
        $this->assertDatabaseHas('product_upgrade_paths', [
            'id' => $path->id,
            'direction' => 'both',
            'enabled' => true,
        ]);
    }
}
