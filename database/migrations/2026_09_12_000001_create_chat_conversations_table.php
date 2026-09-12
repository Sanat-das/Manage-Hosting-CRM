<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The root table of the Slack-like chat.
 *
 * Additive on purpose: the legacy `chat_sessions` / `chat_messages` pair from
 * 2026_07_30_120050_create_support_tables.php is left exactly as it is, so
 * nothing that still reads it breaks while the new engine is built alongside.
 *
 * One table carries all four conversation shapes (channel, dm, group_dm,
 * customer_inbox) because they differ only in participants and in which
 * columns apply. The customer-inbox columns (customer_id, assigned_operator_id,
 * guest_token, status, rating, closed_at) live here rather than in a later
 * migration so that the whole schema rolls back as one documented unit.
 *
 * `department` is a plain string, NOT a foreign key to ticket_departments.slug:
 * that column is a natural key on a table users can rename, and the service
 * layer validates it instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['channel', 'dm', 'group_dm', 'customer_inbox'])->default('channel');
            $table->string('name')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->boolean('is_private')->default(false);
            $table->string('department')->nullable();
            $table->string('topic')->nullable();
            $table->string('purpose')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Customer inbox only — null for every staff conversation.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('assigned_operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_token', 64)->nullable();
            $table->enum('status', ['waiting', 'active', 'closed'])->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('department');
            $table->index('status');
            $table->index('guest_token');
            $table->index('assigned_operator_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
