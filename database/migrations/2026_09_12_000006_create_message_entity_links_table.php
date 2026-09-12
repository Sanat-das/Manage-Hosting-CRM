<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a chat message references a domain entity — a product, a
 * customer, a contact or a ticket — so the reference can be rendered as a card
 * in the message and listed back on the entity's own page.
 *
 * Polymorphic rather than four nullable columns: the set of linkable types is
 * expected to grow, and the whitelist lives in the service layer where it can
 * also be permission-checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_entity_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_conversation_messages')->cascadeOnDelete();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->timestamp('created_at')->nullable();

            $table->index(['linkable_type', 'linkable_id']);
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_entity_links');
    }
};
