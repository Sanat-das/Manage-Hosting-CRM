<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the legacy parent_asset_id column. The canonical component-to-
 * device hierarchy lives in asset_relationships ('contains' edges); the
 * column has been unused by the application since the relationships graph
 * became the single source of truth. Historical migrations keep their
 * references to the column and are intentionally left untouched.
 *
 * Rollback restores the original definition; the dropped values themselves
 * cannot be recovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->dropIndex(['parent_asset_id']);
            $table->dropColumn('parent_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->unsignedInteger('parent_asset_id')->nullable();
            $table->index('parent_asset_id');
        });
    }
};
