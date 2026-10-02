<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The client's option selections keyed by target-product link id,
        // snapshotted at request time; null = product-only upgrade.
        Schema::table('upgrade_requests', function (Blueprint $table) {
            $table->json('options')->nullable()->after('to_billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('upgrade_requests', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
