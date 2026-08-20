<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product billing configuration (Phase 2):
 *
 * - payment_type: free / one_time / recurring — governs which pricing matrix
 *   applies and whether configurable-option modifiers are charged.
 * - quantity_behaviour: none / multiple_services / scaling — how an ordered
 *   quantity is interpreted (kept alongside the legacy sell_single flag).
 * - recurring_cycles_limit: number of renewal cycles before billing ends
 *   (0 = unlimited).
 * - auto_terminate_value + auto_terminate_unit: fixed-term termination
 *   (0 value = disabled).
 * - prorata_enabled / prorata_date / prorata_charge_next_month: prorated
 *   billing on the given day of month (1-28).
 * - early_renewal_mode + early_renewal_days: product-specific early-renewal
 *   windows (billing cycle => days before due date) or the system default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('payment_type', ['free', 'one_time', 'recurring'])->default('recurring')->after('billing_cycle');
            $table->enum('quantity_behaviour', ['none', 'multiple_services', 'scaling'])->default('none')->after('sell_single');
            $table->unsignedInteger('recurring_cycles_limit')->default(0)->after('quantity_behaviour');
            $table->unsignedInteger('auto_terminate_value')->default(0)->after('recurring_cycles_limit');
            $table->enum('auto_terminate_unit', ['days', 'months', 'years'])->default('days')->after('auto_terminate_value');
            $table->boolean('prorata_enabled')->default(false)->after('auto_terminate_unit');
            $table->unsignedTinyInteger('prorata_date')->nullable()->after('prorata_enabled');
            $table->boolean('prorata_charge_next_month')->default(false)->after('prorata_date');
            $table->enum('early_renewal_mode', ['default', 'custom'])->default('default')->after('prorata_charge_next_month');
            $table->json('early_renewal_days')->nullable()->after('early_renewal_mode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'payment_type',
                'quantity_behaviour',
                'recurring_cycles_limit',
                'auto_terminate_value',
                'auto_terminate_unit',
                'prorata_enabled',
                'prorata_date',
                'prorata_charge_next_month',
                'early_renewal_mode',
                'early_renewal_days',
            ]);
        });
    }
};
