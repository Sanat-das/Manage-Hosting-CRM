<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-line billing cycle on order items. Multi-product orders can mix
        // cycles (a monthly VPS plus an annual backup add-on), so each line
        // records the cycle its unit price refers to. Null for legacy lines —
        // callers fall back to the order's billing_cycle.
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('billing_cycle', 20)->nullable()->after('product_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });
    }
};
