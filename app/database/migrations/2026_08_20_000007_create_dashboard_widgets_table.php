<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('widget_key', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'widget_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};