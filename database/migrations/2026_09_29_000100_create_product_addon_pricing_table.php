<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_addon_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_addon_id')->constrained('product_addons')->cascadeOnDelete();
            $table->string('billing_cycle', 20);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('setup_fee', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['product_addon_id', 'billing_cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_addon_pricing');
    }
};
