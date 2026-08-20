<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the existing IPAM ledger host HostingAccount leases:
 *  - ip_addresses.assigned_to_type gains the 'App\Models\HostingAccount'
 *    morph marker (the existing short codes stay valid for legacy rows);
 *  - ip_allocation_history.action gains 'assigned' / 'override';
 *  - ip_allocation_history.ip_address_snapshot widens to TEXT so a full
 *    JSON row snapshot fits (was VARCHAR(45)).
 *
 * Additive only — existing rows and values are untouched.
 */
return new class extends Migration
{
    private const ASSIGNED_TO_TYPES = ['service', 'server', 'customer', 'inventory', 'App\Models\HostingAccount'];

    private const LEGACY_ASSIGNED_TO_TYPES = ['service', 'server', 'customer', 'inventory'];

    private const ACTIONS = ['allocated', 'released', 'reserved', 'unreserved', 'ptr_updated', 'assigned', 'override'];

    private const LEGACY_ACTIONS = ['allocated', 'released', 'reserved', 'unreserved', 'ptr_updated'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // MySQL parses backslash escapes inside ENUM literals, so the
            // morph class-string must appear with doubled backslashes in DDL.
            DB::statement("ALTER TABLE ip_addresses MODIFY assigned_to_type ENUM('service','server','customer','inventory','App\\\\Models\\\\HostingAccount') NULL");
        } else {
            Schema::table('ip_addresses', function (Blueprint $table) {
                $table->enum('assigned_to_type', self::ASSIGNED_TO_TYPES)->nullable()->change();
            });
        }

        Schema::table('ip_allocation_history', function (Blueprint $table) {
            $table->enum('action', self::ACTIONS)->change();
            $table->text('ip_address_snapshot')->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ip_addresses MODIFY assigned_to_type ENUM('service','server','customer','inventory') NULL");
        } else {
            Schema::table('ip_addresses', function (Blueprint $table) {
                $table->enum('assigned_to_type', self::LEGACY_ASSIGNED_TO_TYPES)->nullable()->change();
            });
        }

        Schema::table('ip_allocation_history', function (Blueprint $table) {
            $table->enum('action', self::LEGACY_ACTIONS)->change();
            $table->string('ip_address_snapshot', 45)->change();
        });
    }
};
