<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved replies an operator can drop into the composer.
 *
 * `user_id` is the scope, and it carries the whole permission model:
 *
 *   NULL      a shared reply, visible to everyone, editable only with
 *             chat.manage
 *   set       that person's own reply, visible and editable only by them
 *
 * `created_by` is separate from `user_id` and is never used for authorisation.
 * It answers "who wrote this shared reply", which `user_id = NULL` cannot.
 *
 * `shortcut` is the `/thing` an operator types. There is deliberately no unique
 * index on it: MySQL treats NULLs as distinct, so `unique(user_id, shortcut)`
 * would enforce nothing at all for the shared replies (user_id NULL) that most
 * need it, while looking like it did. Uniqueness within a scope is asserted in
 * StoreCannedReplyRequest, where it can produce a 422 that names the clash.
 *
 * `department` is a plain string for the same reason it is one on
 * `chat_conversations`: ticket_departments.slug is a natural key on a table
 * admins rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_canned_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('shortcut', 64)->nullable();
            $table->text('body');
            $table->string('department')->nullable();

            // Null = shared. Cascades: a personal reply belongs to a person and
            // has no meaning once that person is gone. Shared replies have a
            // null user_id and are untouched by any user deletion.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('uses')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'shortcut']);
            $table->index('department');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_canned_replies');
    }
};
