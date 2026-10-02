<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_upgrade_paths', function (Blueprint $table) {
            $table->enum('direction', ['upgrade', 'downgrade', 'both'])
                ->default('both')
                ->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('product_upgrade_paths', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
