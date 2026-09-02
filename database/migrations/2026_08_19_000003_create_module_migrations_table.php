<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks which migrations have been applied per module, so the migration
 * runner can roll them back on module uninstall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('module_id');
            $table->string('migration');
            $table->unsignedInteger('batch');
            $table->timestamps();

            $table->unique(['module_id', 'migration']);

            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_migrations');
    }
};
