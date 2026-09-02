<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interface rate storage for the polling pipeline (task 5): snmp_if_samples
 * gains the raw octet counters plus the derived bits-per-second rates the
 * batch job computes against the prior snmp_latest payload. Rates are NULL
 * whenever they would be fabricated — first poll, counter reset (decrease)
 * or a collection gap wider than three effective intervals.
 *
 * Guarded re-runnable: skips columns that already exist so replaying module
 * migrations over an upgraded database stays harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('monitoring')->hasTable('snmp_if_samples')) {
            return;
        }

        Schema::connection('monitoring')->table('snmp_if_samples', function (Blueprint $table): void {
            if (! Schema::connection('monitoring')->hasColumn('snmp_if_samples', 'in_octets')) {
                $table->bigInteger('in_octets')->nullable();
            }

            if (! Schema::connection('monitoring')->hasColumn('snmp_if_samples', 'out_octets')) {
                $table->bigInteger('out_octets')->nullable();
            }

            if (! Schema::connection('monitoring')->hasColumn('snmp_if_samples', 'in_bps')) {
                $table->float('in_bps')->nullable();
            }

            if (! Schema::connection('monitoring')->hasColumn('snmp_if_samples', 'out_bps')) {
                $table->float('out_bps')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection('monitoring')->hasTable('snmp_if_samples')) {
            return;
        }

        Schema::connection('monitoring')->table('snmp_if_samples', function (Blueprint $table): void {
            foreach (['in_octets', 'out_octets', 'in_bps', 'out_bps'] as $column) {
                if (Schema::connection('monitoring')->hasColumn('snmp_if_samples', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
