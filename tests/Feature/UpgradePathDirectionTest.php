<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUpgradePath;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\Demo\ProductExtrasSeeder;
use Database\Seeders\Demo\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Per-product upgrade/downgrade direction lists on product_upgrade_paths.
 *
 * Covers the `direction` enum column ('upgrade' | 'downgrade' | 'both'):
 * migration presence + default, model fillable/cast, admin create/edit
 * persistence, and the demo seeder's explicit direction mix.
 *
 * Admin-auth pattern mirrors AdminOrderFlowTest (admin role + permission
 * gates); seeder assertion uses the repo's FK-order seeding idiom
 * (ProductSeeder before ProductExtrasSeeder).
 */
class UpgradePathDirectionTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['product-upgrades.view', 'product-upgrades.manage'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    private function makeProduct(string $name): Product
    {
        return Product::create([
            'name' => $name,
            'is_bundle' => false,
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ]);
    }

    public function test_direction_column_exists_with_both_default(): void
    {
        $this->assertTrue(Schema::hasColumn('product_upgrade_paths', 'direction'));

        $from = $this->makeProduct('Shared Hosting');
        $to = $this->makeProduct('VPS Starter');

        $path = ProductUpgradePath::create([
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
        ]);

        // Eloquent does not hydrate DB defaults into the instance on create();
        // refresh so the assertion reads what the database actually stored.
        $path->refresh();

        $this->assertSame('both', $path->direction);
        $this->assertSame('both', DB::table('product_upgrade_paths')->where('id', $path->id)->value('direction'));
    }

    public function test_direction_is_fillable_and_cast_to_string(): void
    {
        $path = new ProductUpgradePath;

        $this->assertContains('direction', $path->getFillable());
        $this->assertSame('string', $path->getCasts()['direction']);

        $from = $this->makeProduct('Shared Hosting');
        $to = $this->makeProduct('VPS Starter');

        $path = ProductUpgradePath::create([
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'direction' => 'upgrade',
        ]);

        $this->assertSame('upgrade', $path->direction);
        $this->assertIsString($path->direction);
    }

    public function test_admin_create_persists_direction(): void
    {
        $admin = $this->adminUser();

        $from = $this->makeProduct('Shared Hosting');
        $to = $this->makeProduct('VPS Starter');

        $response = $this->actingAs($admin)->post(route('admin.product-upgrades.store'), [
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'enabled' => true,
            'direction' => 'downgrade',
        ]);

        $response->assertRedirect(route('admin.product-upgrades.index'));

        $this->assertDatabaseHas('product_upgrade_paths', [
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'direction' => 'downgrade',
        ]);
    }

    public function test_admin_create_rejects_invalid_direction(): void
    {
        $admin = $this->adminUser();

        $from = $this->makeProduct('Shared Hosting');
        $to = $this->makeProduct('VPS Starter');

        $response = $this->actingAs($admin)->post(route('admin.product-upgrades.store'), [
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'enabled' => true,
            'direction' => 'sideways',
        ]);

        $response->assertSessionHasErrors('direction');
        $this->assertDatabaseMissing('product_upgrade_paths', [
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
        ]);
    }

    public function test_admin_edit_changes_direction(): void
    {
        $admin = $this->adminUser();

        $from = $this->makeProduct('Shared Hosting');
        $to = $this->makeProduct('VPS Starter');

        $path = ProductUpgradePath::create([
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'enabled' => true,
            'direction' => 'upgrade',
        ]);

        $response = $this->actingAs($admin)->put(
            route('admin.product-upgrades.update', $path),
            [
                'from_product_id' => $from->id,
                'to_product_id' => $to->id,
                'enabled' => true,
                'direction' => 'both',
            ]
        );

        $response->assertRedirect(route('admin.product-upgrades.show', $path));

        $this->assertDatabaseHas('product_upgrade_paths', [
            'id' => $path->id,
            'direction' => 'both',
        ]);
    }

    public function test_demo_seeder_rows_carry_expected_directions(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductExtrasSeeder::class);

        $directions = DB::table('product_upgrade_paths as p')
            ->join('products as from_product', 'from_product.id', '=', 'p.from_product_id')
            ->join('products as to_product', 'to_product.id', '=', 'p.to_product_id')
            ->selectRaw('p.direction, from_product.name as from_product_name, to_product.name as to_product_name')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->from_product_name.' → '.$row->to_product_name => $row->direction])
            ->all();

        $this->assertSame('upgrade', $directions['Demo Starter Shared Hosting → Demo Business Shared Hosting'] ?? null);
        $this->assertSame('both', $directions['Demo Cloud VPS 2GB → Demo Cloud VPS 8GB'] ?? null);
        $this->assertSame('downgrade', $directions['Demo Business Shared Hosting → Demo Reseller Bronze'] ?? null);
    }
}
