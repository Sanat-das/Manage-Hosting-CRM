<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original orders schema (2026_07_30_120030) has no order_number /
     * billing_cycle columns. The reference order track relies on both:
     * - order_number: display id + search target (format ORD-{YEAR}-{seq})
     * - billing_cycle: drives next_billing_date seeding on activation and the
     *   recurring billing engine.
     *
     * Additive, non-destructive. Existing rows keep order_number NULL and fall
     * back to "#{id}" for display.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_number', 32)->nullable()->after('id');
            $table->enum('billing_cycle', ['monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'one_time'])
                ->default('monthly')
                ->after('product_id');
            $table->unique('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['order_number']);
            $table->dropColumn(['order_number', 'billing_cycle']);
        });
    }
};
