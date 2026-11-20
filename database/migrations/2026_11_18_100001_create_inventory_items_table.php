<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_asset_id');
            $table->unsignedBigInteger('parent_item_id')->nullable();
            $table->string('name');
            $table->string('label')->nullable();
            $table->string('sku')->nullable();
            $table->unsignedBigInteger('manufacturer_id')->nullable();
            $table->string('part_number')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('asset_tag')->nullable();
            $table->string('live_item_asset_tag')->virtualAs('CASE WHEN asset_tag IS NULL OR deleted_at IS NOT NULL THEN NULL ELSE asset_tag END');
            $table->enum('status', ['ordered', 'received', 'in_stock', 'installed', 'assigned', 'maintenance', 'retired', 'disposed'])->default('installed');
            $table->boolean('discovered')->default(false);
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('inventory_asset_id');
            $table->foreign('inventory_asset_id')
                ->references('id')
                ->on('inventory_assets')
                ->cascadeOnDelete();

            $table->index('parent_item_id');
            $table->foreign('parent_item_id')
                ->references('id')
                ->on('inventory_items')
                ->cascadeOnDelete();

            $table->index('manufacturer_id');
            $table->foreign('manufacturer_id')
                ->references('id')
                ->on('manufacturers')
                ->nullOnDelete();

            $table->unique('live_item_asset_tag', 'inventory_items_live_item_asset_tag_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
