<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discovery identity + inventory linkage for snmp_targets.
 *
 * A successful poll learns a device's identity from the RFC 1213 system group
 * (sysName / sysDescr / sysObjectID). These columns cache that identity on the
 * target and link it to a core inventory_assets row, so a discovered device
 * flows into inventory once and is never duplicated. inventory_asset_id is a
 * plain indexed nullable column (no FK): inventory_assets is a soft-deletable
 * core table, and a hard delete/restore cycle must never break polling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snmp_targets', function (Blueprint $table): void {
            $table->string('sys_name')->nullable()->after('host');
            $table->text('sys_descr')->nullable()->after('sys_name');
            $table->string('sys_object_id')->nullable()->after('sys_descr');
            $table->unsignedBigInteger('inventory_asset_id')->nullable()->index()->after('enabled');
            $table->timestamp('last_discovered_at')->nullable()->after('last_polled_at');
        });
    }

    public function down(): void
    {
        Schema::table('snmp_targets', function (Blueprint $table): void {
            $table->dropColumn([
                'sys_name',
                'sys_descr',
                'sys_object_id',
                'inventory_asset_id',
                'last_discovered_at',
            ]);
        });
    }
};
