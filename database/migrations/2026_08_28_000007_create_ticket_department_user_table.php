<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff <-> department binding.
     *
     * Mirrors the adminlte_role_user pivot style: foreignId + constrained
     * + cascadeOnDelete, unique composite, separate index on user_id.
     * Timestamps are kept for audit (when staff joined a department).
     * Non-client enforcement lives in the controller/service, not the DB.
     */
    public function up(): void
    {
        Schema::create('ticket_department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_department_id')->constrained('ticket_departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ticket_department_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_department_user');
    }
};
