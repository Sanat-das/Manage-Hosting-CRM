<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the legacy devices.asset_type with the Device Type catalog's
 * category (NetBox-strict): `device_types.category` becomes the single
 * place where server / switch / pdu / other_hardware lives, and devices
 * reference their type via the already-present `device_type_id` FK.
 *
 * Steps, in order:
 *  1. FAIL CLOSED — a live (deleted_at NULL) device without device_type_id
 *     aborts the migration with the offending asset_tags listed. Nothing is
 *     altered before this check passes.
 *  2. device_types.category is added as a NULLABLE enum, backfilled from the
 *     most common asset_type among the devices (incl. soft-deleted rows)
 *     joined to each type; types whose top value is not one of the four
 *     surviving categories (or that have no devices) get 'other_hardware'.
 *     The column is then changed to NOT NULL.
 *  3. The composite (asset_type, status) index devices_asset_type_status_index
 *     is dropped, then the asset_type column is dropped last.
 *
 * down() re-adds asset_type as a NULLABLE enum and restores the composite
 * index, but the original per-row asset_type values are UNRECOVERABLE —
 * down() restores structure only, never data. The device_types.category
 * values are likewise dropped.
 */
return new class extends Migration
{
    private const CATEGORIES = ['server', 'switch', 'pdu', 'other_hardware'];

    /** Legacy asset_type values that map onto a surviving category. */
    private const CATEGORY_MAP = [
        'server' => 'server',
        'switch' => 'switch',
        'pdu' => 'pdu',
        'other_hardware' => 'other_hardware',
    ];

    public function up(): void
    {
        // 1. Fail closed: every live device must reference a device type.
        $offenders = DB::table('devices')
            ->whereNull('deleted_at')
            ->whereNull('device_type_id')
            ->pluck('asset_tag')
            ->all();

        if ($offenders !== []) {
            throw new RuntimeException(
                'Refusing to drop devices.asset_type: live device(s) without a device_type_id: '
                .implode(', ', $offenders)
                .'. Link them to a device type first.'
            );
        }

        // 2a. Add the category column as nullable first.
        Schema::table('device_types', function (Blueprint $table) {
            $table->enum('category', self::CATEGORIES)->nullable();
        });

        // 2b. Backfill: most common asset_type per device type, including
        //     soft-deleted devices. Types with no devices (or an unmapped
        //     top value) fall back to 'other_hardware'.
        $topByType = DB::table('devices')
            ->select('device_type_id', 'asset_type', DB::raw('COUNT(*) as n'))
            ->whereNotNull('device_type_id')
            ->groupBy('device_type_id', 'asset_type')
            ->orderBy('device_type_id')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        $categoryByType = [];
        foreach ($topByType as $row) {
            $typeId = (int) $row->device_type_id;
            if (isset($categoryByType[$typeId])) {
                continue; // rows arrive type_id ASC, count DESC — first row is the winner
            }
            $categoryByType[$typeId] = self::CATEGORY_MAP[$row->asset_type] ?? 'other_hardware';
        }

        $typeIds = DB::table('device_types')->pluck('id');
        foreach ($typeIds as $typeId) {
            DB::table('device_types')
                ->where('id', $typeId)
                ->update(['category' => $categoryByType[(int) $typeId] ?? 'other_hardware']);
        }

        // 2c. Lock the column down to NOT NULL.
        Schema::table('device_types', function (Blueprint $table) {
            $table->enum('category', self::CATEGORIES)->change();
        });

        // 3a. Drop the asset_type + status composite index.
        if (Schema::hasIndex('devices', 'devices_asset_type_status_index')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropIndex('devices_asset_type_status_index');
            });
        }

        // 3b. And finally the column itself.
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('asset_type');
        });
    }

    /**
     * Re-add the dropped structure, NOT the dropped data. asset_type comes
     * back as a NULLABLE enum with no values restored; category on
     * device_types is dropped; the (asset_type, status) composite index is
     * recreated (on an all-NULL asset_type column).
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->enum('asset_type', ['server', 'ram_module', 'cpu', 'ssd', 'hdd', 'gpu', 'raid_controller', 'nic', 'switch', 'pdu', 'other_hardware', 'software_license', 'ipv4_address', 'ipv6_address', 'ssl_certificate', 'domain'])
                ->nullable()
                ->after('serial_number');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->index(['asset_type', 'status'], 'devices_asset_type_status_index');
        });

        Schema::table('device_types', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
