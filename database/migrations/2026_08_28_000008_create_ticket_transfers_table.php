<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit for department transfers.
     *
     * Mirrors the audit style in 2026_07_30_120050_create_support_tables.php
     * (ticket_replies: foreignId + constrained + cascadeOnDelete) and
     * 2026_07_30_120060_create_audit_tables.php (timestamp created_at only).
     * No updated_at / softDeletes — audit rows are never mutated.
     */
    public function up(): void
    {
        Schema::create('ticket_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('from_department', 50);
            $table->string('to_department', 50);
            $table->unsignedInteger('assigned_to')->nullable();
            $table->unsignedInteger('assigned_from')->nullable();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent()->nullable();

            $table->index('ticket_id');
            $table->index('to_department');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_transfers');
    }
};
