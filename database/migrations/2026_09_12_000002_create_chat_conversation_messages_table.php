<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages of the Slack-like chat.
 *
 * Named `chat_conversation_messages` rather than `chat_messages` because the
 * legacy `chat_messages` table is still in place and untouched.
 *
 * Threads are a self-referencing `parent_id`, one level deep by convention:
 * a reply points at the root message, never at another reply.
 *
 * Deletes are soft so a deleted parent still anchors its thread — the UI
 * renders it as "[deleted]" instead of orphaning the replies.
 *
 * Created before chat_participants because participants.last_read_message_id
 * points here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_token', 64)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('chat_conversation_messages')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('parent_id');
            $table->index('created_at');
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversation_messages');
    }
};
