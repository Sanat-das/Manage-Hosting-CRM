<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained('manufacturers')->cascadeOnDelete();
            $table->string('model', 255);
            $table->string('slug', 255)->unique();
            $table->string('part_number', 255)->nullable();
            $table->unsignedInteger('u_height')->default(1);
            $table->boolean('is_full_depth')->default(true);
            $table->unsignedInteger('power_draw_watts')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['manufacturer_id', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_types');
    }
};
