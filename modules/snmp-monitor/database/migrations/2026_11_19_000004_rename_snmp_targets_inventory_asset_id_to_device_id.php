<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the inventory linkage column on snmp_targets to `device_id` (Stage B
 * of the NetBox alignment plan). The core rename migration
 * (2026_11_19_000002_rename_inventory_assets_to_devices) runs before module
 * migrations on an existing install and already rewrote this column in place;
 * on a fresh install the module migration chain creates `inventory_asset_id`
 * first (2026_10_05_120000) and this migration renames it afterwards. Either
 * way the end state is `device_id`.
 *
 * Guarded, so re-running (or running after the core rename) is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('snmp_targets')) {
            return;
        }

        if (Schema::hasColumn('snmp_targets', 'device_id')) {
            return;
        }

        if (! Schema::hasColumn('snmp_targets', 'inventory_asset_id')) {
            return;
        }

        Schema::table('snmp_targets', function (Blueprint $table): void {
            $table->renameColumn('inventory_asset_id', 'device_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('snmp_targets')) {
            return;
        }

        if (! Schema::hasColumn('snmp_targets', 'device_id')) {
            return;
        }

        Schema::table('snmp_targets', function (Blueprint $table): void {
            $table->renameColumn('device_id', 'inventory_asset_id');
        });
    }
};
