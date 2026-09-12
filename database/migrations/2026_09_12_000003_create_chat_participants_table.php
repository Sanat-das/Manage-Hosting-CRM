<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership of a conversation, and the single source of truth for "read up to".
 *
 * There is deliberately NO chat_read_state table: `last_read_message_id` on
 * this row is the whole read model. Unread count is
 * `messages where id > last_read_message_id and user_id <> me`, which is one
 * indexed range scan instead of a per-message join table that grows without
 * bound.
 *
 * A guest participant has a null user_id and a guest_token instead. The
 * unique(conversation_id, user_id) index still allows many guests in one
 * conversation because both MySQL and SQLite treat NULLs as distinct in a
 * unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('guest_token', 64)->nullable();
            $table->enum('role', ['member', 'admin'])->default('member');
            $table->timestamp('joined_at')->nullable();
            $table->foreignId('last_read_message_id')->nullable()
                ->constrained('chat_conversation_messages')->nullOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
            $table->index('guest_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_participants');
    }
};
