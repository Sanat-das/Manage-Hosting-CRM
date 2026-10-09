<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->dropUnique(['asset_tag']);
            $table->string('live_asset_tag')->nullable()->virtualAs('CASE WHEN deleted_at IS NULL THEN asset_tag ELSE NULL END');
            $table->unique('live_asset_tag');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->dropUnique(['live_asset_tag']);
            $table->dropColumn('live_asset_tag');
            $table->unique('asset_tag');
        });
    }
};
