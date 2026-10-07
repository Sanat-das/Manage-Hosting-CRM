<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair ip_addresses rows whose inventory asset link was written to only one
 * of the two columns that track it, so IPAM status/availability read
 * consistently.
 *
 * An asset link is represented twice: the polymorphic pair
 * (`assigned_to_type` = 'inventory' + `assigned_to_id`) drives the computed
 * status and the hosting availability scope, while `inventory_asset_id` is the
 * IP Manager pointer. Older form saves set only `inventory_asset_id`, leaving a
 * linked address still reading "available" and leaseable by hosting.
 *
 * This is a normalization, not a user action, so it deliberately writes no
 * `ip_allocation_history` rows: the ledger records manual assign/release
 * changes, and backfilling historical rows here would invent timestamps and
 * actors that never existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Linked column set, polymorphic pair missing.
        DB::table('ip_addresses')
            ->whereNotNull('inventory_asset_id')
            ->whereNull('assigned_to_type')
            ->update([
                'assigned_to_type' => 'inventory',
                'assigned_to_id' => DB::raw('inventory_asset_id'),
                'type' => DB::raw("CASE WHEN type = 'available' THEN 'assigned' ELSE type END"),
            ]);

        // Polymorphic pair set, linked column missing.
        DB::table('ip_addresses')
            ->where('assigned_to_type', 'inventory')
            ->whereNull('inventory_asset_id')
            ->update([
                'inventory_asset_id' => DB::raw('assigned_to_id'),
            ]);
    }

    public function down(): void
    {
        // Irreversible: the pre-migration values cannot be distinguished from
        // rows that were already consistent, so any attempt to undo would
        // corrupt the latter. Nothing to do.
    }
};
