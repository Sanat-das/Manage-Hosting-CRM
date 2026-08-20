<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_upgrade_paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('to_product_id')->constrained('products')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['from_product_id', 'to_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_upgrade_paths');
    }
};
