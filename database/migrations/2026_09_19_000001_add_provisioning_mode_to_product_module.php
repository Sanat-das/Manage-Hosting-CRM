<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-link Auto/Manual provisioning mode.
 *
 * Each enabled product-module link decides whether the module provisions
 * automatically or only on manual operator action (hosting page buttons).
 * Defaults to 'auto' so existing links keep their current behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_module', function (Blueprint $table) {
            $table->string('provisioning_mode', 16)->default('auto')->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('product_module', function (Blueprint $table) {
            $table->dropColumn('provisioning_mode');
        });
    }
};
