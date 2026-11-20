<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the `asset_relationships` graph end-to-end (Stage A of the NetBox
 * alignment plan). The graph duplicated the semantics now owned by
 * `inventory_items` (contained/component rows) and `port_connections`
 * (cables), and was the single worst source of stale inventory data.
 *
 * User approved the full drop on 2026-11-19, including loss of the 5 rows
 * currently present on the local database. There is NO data migration and
 * NO data restore — the pre-drop mysqldump taken at Stage 0b is the only
 * undo.
 *
 * Permission removal mirrors 2026_10_09_000005_backfill_logs_permissions.php
 * inverted: the `asset-relationships.view` / `asset-relationships.manage`
 * rows are deleted from `adminlte_permissions` together with their
 * `adminlte_permission_role` pivots. Guarded on the three adminlte_* tables
 * existing; a no-op on a fresh install because the seeder no longer
 * declares these permissions.
 *
 * down(): recreates the `asset_relationships` table SCHEMA empty (shape
 * copied from 2026_08_06_000001_create_asset_relationships_table.php) and
 * re-inserts the two permission ROWS bare so a rollback is functional.
 * Pivot grants are UNRECOVERABLE — they are not re-created; re-granting
 * must be done manually via the Roles UI or from the Stage 0b dump.
 * No data is restored.
 */
return new class extends Migration
{
    /** Permission name => label, matching the rows removed from AdminLteRbacSeeder. */
    private const PERMISSIONS = [
        'asset-relationships.view' => 'View Asset Relationships',
        'asset-relationships.manage' => 'Manage Asset Relationships',
    ];

    public function up(): void
    {
        if (Schema::hasTable('asset_relationships')) {
            $count = DB::table('asset_relationships')->count();
            $this->output("asset_relationships: dropping table with {$count} row(s). Data is NOT migrated; the Stage 0b dump is the only undo.");
            Schema::dropIfExists('asset_relationships');
        } else {
            $this->output('asset_relationships: table absent, nothing to drop.');
        }

        foreach (['adminlte_roles', 'adminlte_permissions', 'adminlte_permission_role'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = DB::table('adminlte_permissions')->where('name', $name)->value('id');

            if ($permissionId === null) {
                continue;
            }

            DB::table('adminlte_permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('adminlte_permissions')->where('id', $permissionId)->delete();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_relationships')) {
            Schema::create('asset_relationships', function (Blueprint $table) {
                $table->id();
                $table->string('parent_kind', 100);
                $table->unsignedBigInteger('parent_id');
                $table->string('child_kind', 100);
                $table->unsignedBigInteger('child_id');
                $table->string('relationship_type', 50)->default('hosted_on');
                $table->string('label')->nullable();
                $table->integer('sort_order')->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['parent_kind', 'parent_id']);
                $table->index(['child_kind', 'child_id']);
                $table->unique([
                    'parent_kind',
                    'parent_id',
                    'child_kind',
                    'child_id',
                    'relationship_type',
                ], 'asset_relationships_parent_child_type_unique');
            });
        }

        if (! Schema::hasTable('adminlte_permissions')) {
            return;
        }

        // Bare permission rows only — pivot grants are unrecoverable and
        // are deliberately NOT re-created here.
        $now = now();

        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('adminlte_permissions')->insertOrIgnore([
                'name' => $name,
                'label' => $label,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function output(string $message): void
    {
        $stream = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');

        if ($stream !== false) {
            fwrite($stream, $message.PHP_EOL);

            if ($stream !== STDERR) {
                fclose($stream);
            }
        }
    }
};
