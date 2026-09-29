<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('parent_item_id')->nullable()->after('order_id')
                ->constrained('order_items')->nullOnDelete();
            $table->foreignId('product_addon_id')->nullable()->after('parent_item_id')
                ->constrained('product_addons')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['order_id', 'parent_item_id']);
            $table->index('product_addon_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'parent_item_id']);
            $table->dropIndex(['product_addon_id']);
        });

        if (Schema::hasColumn('order_items', 'product_addon_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_addon_id');
            });
        }

        if (Schema::hasColumn('order_items', 'parent_item_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('parent_item_id');
            });
        }
    }
};
