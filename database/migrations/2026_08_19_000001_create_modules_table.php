<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module registry (WP-style plugins table). Status flows installed -> active
 * -> disabled / crashed; `config` is per-module global configuration as JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('version');
            $table->string('status')->default('installed');
            $table->string('provider');
            $table->json('manifest')->nullable();
            $table->json('config')->nullable();
            $table->timestamp('crashed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
