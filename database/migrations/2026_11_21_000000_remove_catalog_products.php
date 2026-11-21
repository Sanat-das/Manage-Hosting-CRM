<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the reference-only `catalog_products` table.
 *
 * The storefront sells `products`; the catalog table has no bridge to them and
 * held no rows at retirement time. `service_instances.catalog_product_id` has
 * been nullable since 2026_09_06 and every reader null-safes the relation, so
 * the column and the table it points at can both go.
 */
return new class extends Migration
{
    /** @var array<string, string> permission name => label */
    private const PERMISSIONS = [
        'catalog-products.view' => 'View Catalog Products',
        'catalog-products.manage' => 'Manage Catalog Products',
    ];

    public function up(): void
    {
        if (Schema::hasTable('adminlte_permissions')) {
            $permissionIds = DB::table('adminlte_permissions')
                ->whereIn('name', array_keys(self::PERMISSIONS))
                ->pluck('id');

            if ($permissionIds->isNotEmpty()) {
                $roleIds = collect();

                if (Schema::hasTable('adminlte_permission_role')) {
                    $roleIds = DB::table('adminlte_permission_role')
                        ->whereIn('permission_id', $permissionIds)
                        ->pluck('role_id')
                        ->unique();

                    DB::table('adminlte_permission_role')
                        ->whereIn('permission_id', $permissionIds)
                        ->delete();
                }

                DB::table('adminlte_permissions')
                    ->whereIn('id', $permissionIds)
                    ->delete();

                // A role whose only grants were the retired permissions is itself
                // retired: drop its user links too. Roles that still hold other
                // permissions (admin holds the whole inventory) are untouched.
                if ($roleIds->isNotEmpty() && Schema::hasTable('adminlte_role_user')) {
                    $orphanedRoleIds = $roleIds->reject(
                        fn (int $roleId): bool => DB::table('adminlte_permission_role')
                            ->where('role_id', $roleId)
                            ->exists()
                    );

                    if ($orphanedRoleIds->isNotEmpty()) {
                        DB::table('adminlte_role_user')
                            ->whereIn('role_id', $orphanedRoleIds)
                            ->delete();
                    }
                }
            }
        }

        if (Schema::hasTable('service_instances') && Schema::hasColumn('service_instances', 'catalog_product_id')) {
            Schema::table('service_instances', function (Blueprint $table): void {
                // `catalog_product_id` resolves the conventional
                // service_instances_catalog_product_id_foreign name.
                $table->dropForeign(['catalog_product_id']);
                $table->dropIndex(['catalog_product_id']);
                $table->dropColumn('catalog_product_id');
            });
        }

        Schema::dropIfExists('catalog_products');
    }

    public function down(): void
    {
        if (! Schema::hasTable('catalog_products')) {
            Schema::create('catalog_products', function (Blueprint $table): void {
                $table->id();
                $table->string('sku')->unique();
                $table->string('name');
                $table->unsignedBigInteger('category_id')->nullable();
                $table->text('description')->nullable();
                $table->enum('product_type', ['shared_hosting', 'reseller', 'vps', 'dedicated', 'domain', 'addon', 'bundle', 'license', 'other'])->default('shared_hosting');
                $table->enum('provisioning_method', ['manual', 'cpanel', 'plesk', 'directadmin', 'proxmox', 'vmware', 'hyperv', 'solusvm', 'virtualizor', 'docker', 'kubernetes', 'api', 'custom_script'])->default('manual');
                $table->json('provisioning_config')->nullable();
                $table->enum('billing_model', ['one_time', 'recurring', 'usage_based', 'tiered'])->default('recurring');
                $table->boolean('require_domain')->default(false);
                $table->boolean('show_in_order')->default(true);
                $table->boolean('only_admin')->default(false);
                $table->integer('sort_order')->default(0);
                $table->enum('status', ['active', 'inactive', 'retired'])->default('active');
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('service_instances') && ! Schema::hasColumn('service_instances', 'catalog_product_id')) {
            Schema::table('service_instances', function (Blueprint $table): void {
                $table->foreignId('catalog_product_id')
                    ->nullable()
                    ->constrained('catalog_products')
                    ->restrictOnDelete();
                $table->index('catalog_product_id');
            });
        }

        if (Schema::hasTable('adminlte_permissions') && Schema::hasTable('adminlte_roles')) {
            $now = now();
            $adminRoleId = DB::table('adminlte_roles')->where('name', 'admin')->value('id');

            foreach (self::PERMISSIONS as $name => $label) {
                $id = DB::table('adminlte_permissions')->where('name', $name)->value('id');

                if ($id === null) {
                    $id = DB::table('adminlte_permissions')->insertGetId([
                        'name' => $name,
                        'label' => $label,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if ($adminRoleId !== null && Schema::hasTable('adminlte_permission_role')) {
                    DB::table('adminlte_permission_role')->firstOrCreate([
                        'permission_id' => $id,
                        'role_id' => $adminRoleId,
                    ]);
                }
            }
        }
    }
};
