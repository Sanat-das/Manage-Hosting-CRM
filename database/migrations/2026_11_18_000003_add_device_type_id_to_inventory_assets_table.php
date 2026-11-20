<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('device_type_id')->nullable()->after('asset_type');
            $table->index('device_type_id');
            $table->foreign('device_type_id')->references('id')->on('device_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->dropForeign(['device_type_id']);
            $table->dropIndex(['device_type_id']);
            $table->dropColumn('device_type_id');
        });
    }
};
