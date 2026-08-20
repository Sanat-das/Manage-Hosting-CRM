<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('subject');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['open', 'pending', 'resolved', 'closed'])->default('open');
            $table->enum('department', ['sales', 'support', 'billing', 'technical'])->default('support');
            $table->unsignedInteger('assigned_to')->nullable();
            $table->dateTime('last_reply_at')->nullable();
            $table->timestamps();

            $table->index('ticket_no');
            $table->index('customer_id');
            $table->index('assigned_to');
            $table->index('status');
            $table->index('priority');
        });

        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->boolean('is_staff')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index('ticket_id');
        });

        Schema::create('knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->enum('category', ['getting_started', 'hosting', 'domains', 'email', 'billing', 'technical'])->default('hosting');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('helpful')->default(0);
            $table->unsignedInteger('not_helpful')->default(0);
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamps();

            $table->index('slug');
            $table->index('category');
            $table->index('status');
        });

        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id')->nullable();
            $table->unsignedInteger('operator_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->enum('department', ['sales', 'support', 'billing', 'technical'])->default('support');
            $table->enum('status', ['waiting', 'active', 'closed'])->default('waiting');
            $table->tinyInteger('rating')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();

            $table->index('status');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->unsignedInteger('user_id')->nullable();
            $table->enum('sender_type', ['client', 'operator', 'system'])->default('client');
            $table->text('message');
            $table->timestamp('created_at')->nullable();

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_sessions');
        Schema::dropIfExists('knowledge_base');
        Schema::dropIfExists('ticket_replies');
        Schema::dropIfExists('tickets');
    }
};
