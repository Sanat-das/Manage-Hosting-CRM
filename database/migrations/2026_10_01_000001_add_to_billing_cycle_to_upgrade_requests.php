<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Target billing cycle for the upgrade: the cycle the served item and
        // the order switch to when the request is applied. Null = keep the
        // order's cycle (pre-cycle-change behaviour), matching WHMCS's
        // newproductbillingcycle semantics.
        Schema::table('upgrade_requests', function (Blueprint $table) {
            $table->string('to_billing_cycle', 20)->nullable()->after('billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('upgrade_requests', function (Blueprint $table) {
            $table->dropColumn('to_billing_cycle');
        });
    }
};
