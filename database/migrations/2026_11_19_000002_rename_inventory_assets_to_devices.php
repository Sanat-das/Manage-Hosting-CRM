<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename `inventory_assets` to `devices` (Stage B of the NetBox
 * alignment plan). Single migration, MySQL/MariaDB + SQLite compatible.
 *
 * The ENUM definition below is copied verbatim from
 * information_schema.COLUMNS on database `local` (MariaDB 10.6.23) on
 * 2026-11-19 and MUST match the prod ENUM as of 2026-11-19:
 *   enum('service','server','customer','inventory','App\Models\HostingAccount')
 *
 * ENUM cutover is three steps: widen to include both 'inventory' and
 * 'device', swap the data, then remove 'inventory'. ip_addresses.device_id
 * and resource_allocations.device_id are widened from unsignedInteger to
 * unsignedBigInteger in the same migration to match the BIGINT parent.
 *
 * Data UPDATEs (ip_allocation_history, taggables, audit_log,
 * automation_log) are idempotent and no-ops on fresh installs.
 *
 * Historical ip_address_snapshot JSON payloads are FROZEN — old snapshots
 * may contain "inventory" as a historical assigned_to_type forever and
 * must NOT be rewritten. The SNMP module keys `auto_inventory` /
 * `auto_ports` are likewise KEPT (they live inside prod product_module
 * JSON blobs).
 *
 * down() is the exact reverse (renames back, ENUM three-step reversed,
 * data UPDATEs reversed). Never run down() on prod after new-code traffic.
 */
return new class extends Migration
{
    /** ip_addresses.assigned_to_type ENUM with BOTH 'inventory' and 'device'. */
    private const ASSIGNED_TO_TYPES_BOTH = ['service', 'server', 'customer', 'inventory', 'device', 'App\Models\HostingAccount'];

    /** ip_addresses.assigned_to_type ENUM with 'inventory' removed. */
    private const ASSIGNED_TO_TYPES_DEVICE = ['service', 'server', 'customer', 'device', 'App\Models\HostingAccount'];

    /** Pre-migration ENUM, verbatim from prod/local on 2026-11-19. */
    private const ASSIGNED_TO_TYPES_LEGACY = ['service', 'server', 'customer', 'inventory', 'App\Models\HostingAccount'];

    public function up(): void
    {
        // 1. Rename the table. InnoDB rewrites intra-schema FK references to
        //    the renamed table; constraint names are preserved and renamed in step 3.
        Schema::rename('inventory_assets', 'devices');

        // 2. Rename the devices-table indexes.
        Schema::table('devices', function (Blueprint $table) {
            $table->renameIndex('inventory_assets_live_asset_tag_unique', 'devices_live_asset_tag_unique');
            $table->renameIndex('inventory_assets_asset_type_status_index', 'devices_asset_type_status_index');
            $table->renameIndex('inventory_assets_datacenter_id_index', 'devices_datacenter_id_index');
            $table->renameIndex('inventory_assets_rack_id_index', 'devices_rack_id_index');
            $table->renameIndex('inventory_assets_device_type_id_index', 'devices_device_type_id_index');
        });

        // 3. Column renames + FK drop/re-add with new constraint names.
        //    FKs are dropped by column list so SQLite (which cannot drop an
        //    FK by name) rebuilds the table correctly; the conventional names
        //    the column list computes are exactly the MySQL constraint names.
        //    licenses.device_id stays unique (existing unique index is column-based).
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropForeign(['inventory_asset_id']);
            $table->renameColumn('inventory_asset_id', 'device_id');
        });
        Schema::table('licenses', function (Blueprint $table) {
            $table->foreign('device_id', 'licenses_device_id_foreign')->references('id')->on('devices')->restrictOnDelete();
        });

        Schema::table('device_ports', function (Blueprint $table) {
            $table->dropForeign(['inventory_asset_id']);
            $table->renameColumn('inventory_asset_id', 'device_id');
        });
        Schema::table('device_ports', function (Blueprint $table) {
            $table->foreign('device_id', 'device_ports_device_id_foreign')->references('id')->on('devices')->cascadeOnDelete();
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropForeign(['inventory_asset_id']);
            $table->renameColumn('inventory_asset_id', 'device_id');
        });
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreign('device_id', 'inventory_items_device_id_foreign')->references('id')->on('devices')->cascadeOnDelete();
        });

        // 3b. SQLite table rebuilds (dropForeign / renameColumn) silently
        //     drop enum CHECK constraints; re-apply the ones that were lost.
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->enum('status', ['ordered', 'received', 'in_stock', 'installed', 'assigned', 'maintenance', 'retired', 'disposed'])->default('installed')->change();
        });
        Schema::table('licenses', function (Blueprint $table) {
            $table->enum('license_type', ['windows', 'cpanel', 'plesk', 'litespeed', 'cloudlinux', 'directadmin', 'virtualizor', 'solusvm', 'other'])->change();
            $table->enum('status', ['active', 'expired', 'revoked', 'pending'])->default('active')->change();
        });

        // 4. Type fixes alongside the renames (unsignedInteger -> unsignedBigInteger).
        Schema::table('resource_allocations', function (Blueprint $table) {
            $table->renameColumn('inventory_asset_id', 'device_id');
        });
        Schema::table('resource_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable()->change();
        });

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->renameColumn('inventory_asset_id', 'device_id');
        });
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable()->change();
        });

        // 5. snmp_targets.device_id has no FK and is already BIGINT — plain rename.
        //    Guarded: the snmp-monitor module migrations run AFTER the core
        //    chain (on activation), so on a fresh install the table does not
        //    exist yet — the module's own rename migration
        //    (2026_11_19_000004) covers that path.
        if (Schema::hasTable('snmp_targets') && Schema::hasColumn('snmp_targets', 'inventory_asset_id')) {
            Schema::table('snmp_targets', function (Blueprint $table) {
                $table->renameColumn('inventory_asset_id', 'device_id');
            });
        }

        // 6. ENUM three-step for ip_addresses.assigned_to_type (widen, swap, remove).
        $this->alterAssignedToTypeEnum(self::ASSIGNED_TO_TYPES_BOTH);
        DB::table('ip_addresses')->where('assigned_to_type', 'inventory')->update(['assigned_to_type' => 'device']);
        $this->alterAssignedToTypeEnum(self::ASSIGNED_TO_TYPES_DEVICE);

        // 7. Idempotent data UPDATEs.
        DB::table('ip_allocation_history')->where('previous_assigned_to_type', 'inventory')->update(['previous_assigned_to_type' => 'device']);
        DB::table('ip_allocation_history')->where('new_assigned_to_type', 'inventory')->update(['new_assigned_to_type' => 'device']);
        DB::table('taggables')->where('taggable_type', 'App\Models\InventoryAsset')->update(['taggable_type' => 'App\Models\Device']);
        DB::table('audit_log')->where('entity_type', 'inventory_asset')->update(['entity_type' => 'device']);
        DB::table('automation_log')->where('entity_type', 'inventory_asset')->update(['entity_type' => 'device']);
    }

    public function down(): void
    {
        DB::table('ip_allocation_history')->where('previous_assigned_to_type', 'device')->update(['previous_assigned_to_type' => 'inventory']);
        DB::table('ip_allocation_history')->where('new_assigned_to_type', 'device')->update(['new_assigned_to_type' => 'inventory']);
        DB::table('taggables')->where('taggable_type', 'App\Models\Device')->update(['taggable_type' => 'App\Models\InventoryAsset']);
        DB::table('audit_log')->where('entity_type', 'device')->update(['entity_type' => 'inventory_asset']);
        DB::table('automation_log')->where('entity_type', 'device')->update(['entity_type' => 'inventory_asset']);

        $this->alterAssignedToTypeEnum(self::ASSIGNED_TO_TYPES_BOTH);
        DB::table('ip_addresses')->where('assigned_to_type', 'device')->update(['assigned_to_type' => 'inventory']);
        $this->alterAssignedToTypeEnum(self::ASSIGNED_TO_TYPES_LEGACY);

        // Drop the device-era FKs first, rename columns back, then rename the
        // table, and only then re-add the legacy FKs (they reference `inventory_assets`).
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
        });
        Schema::table('device_ports', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
        });
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
        });

        if (Schema::hasTable('snmp_targets') && Schema::hasColumn('snmp_targets', 'device_id')) {
            Schema::table('snmp_targets', function (Blueprint $table) {
                $table->renameColumn('device_id', 'inventory_asset_id');
            });
        }

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->unsignedInteger('device_id')->nullable()->change();
        });
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->renameColumn('device_id', 'inventory_asset_id');
        });

        Schema::table('resource_allocations', function (Blueprint $table) {
            $table->unsignedInteger('device_id')->nullable()->change();
        });
        Schema::table('resource_allocations', function (Blueprint $table) {
            $table->renameColumn('device_id', 'inventory_asset_id');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->renameColumn('device_id', 'inventory_asset_id');
        });

        Schema::table('device_ports', function (Blueprint $table) {
            $table->renameColumn('device_id', 'inventory_asset_id');
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->renameColumn('device_id', 'inventory_asset_id');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->renameIndex('devices_live_asset_tag_unique', 'inventory_assets_live_asset_tag_unique');
            $table->renameIndex('devices_asset_type_status_index', 'inventory_assets_asset_type_status_index');
            $table->renameIndex('devices_datacenter_id_index', 'inventory_assets_datacenter_id_index');
            $table->renameIndex('devices_rack_id_index', 'inventory_assets_rack_id_index');
            $table->renameIndex('devices_device_type_id_index', 'inventory_assets_device_type_id_index');
        });

        Schema::rename('devices', 'inventory_assets');

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreign('inventory_asset_id', 'inventory_items_inventory_asset_id_foreign')->references('id')->on('inventory_assets')->cascadeOnDelete();
        });

        Schema::table('device_ports', function (Blueprint $table) {
            $table->foreign('inventory_asset_id', 'device_ports_inventory_asset_id_foreign')->references('id')->on('inventory_assets')->cascadeOnDelete();
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->foreign('inventory_asset_id', 'licenses_inventory_asset_id_foreign')->references('id')->on('inventory_assets')->restrictOnDelete();
        });

        // Mirror up(): SQLite table rebuilds drop enum CHECK constraints;
        // re-apply the ones that were lost.
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->enum('status', ['ordered', 'received', 'in_stock', 'installed', 'assigned', 'maintenance', 'retired', 'disposed'])->default('installed')->change();
        });
        Schema::table('licenses', function (Blueprint $table) {
            $table->enum('license_type', ['windows', 'cpanel', 'plesk', 'litespeed', 'cloudlinux', 'directadmin', 'virtualizor', 'solusvm', 'other'])->change();
            $table->enum('status', ['active', 'expired', 'revoked', 'pending'])->default('active')->change();
        });
    }

    /**
     * ALTER the ip_addresses.assigned_to_type ENUM. MySQL/MariaDB gets DDL
     * with doubled backslashes (MySQL parses backslash escapes inside ENUM
     * literals). The definition must match prod as of 2026-11-19.
     *
     * @param  array<int, string>  $types
     */
    private function alterAssignedToTypeEnum(array $types): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $literals = implode(',', array_map(fn (string $type) => "'".str_replace('\\', '\\\\', $type)."'", $types));
            DB::statement("ALTER TABLE ip_addresses MODIFY assigned_to_type ENUM({$literals}) NULL");

            return;
        }

        Schema::table('ip_addresses', function (Blueprint $table) use ($types) {
            $table->enum('assigned_to_type', $types)->nullable()->change();
        });
    }
};
