<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('action', 50);
            $table->string('entity_type', 50);
            $table->unsignedInteger('entity_id')->nullable();
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
            $table->index('action');
            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });

        Schema::create('automation_log', function (Blueprint $table) {
            $table->id();
            $table->string('action', 50);
            $table->string('entity_type', 50);
            $table->unsignedInteger('entity_id')->nullable();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->text('message')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->index('status');
            $table->index('action');
        });

        Schema::create('email_queue', function (Blueprint $table) {
            $table->id();
            $table->string('to_email');
            $table->string('subject');
            $table->text('body');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->tinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->dateTime('sent_at')->nullable();

            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id')->nullable();
            $table->string('to_email');
            $table->string('subject', 500);
            $table->string('template_name')->nullable();
            $table->enum('status', ['sent', 'failed', 'queued'])->default('sent');
            $table->timestamp('created_at')->nullable();

            $table->index('customer_id');
        });

        Schema::create('cron_logs', function (Blueprint $table) {
            $table->id();
            $table->string('job_name');
            $table->string('command', 500)->nullable();
            $table->enum('status', ['pending', 'running', 'success', 'failed'])->default('pending');
            $table->text('message')->nullable();
            $table->unsignedInteger('domains_processed')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('status');
            $table->index('job_name');
            $table->index('started_at');
        });

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('note');
            $table->boolean('is_important')->default(false);
            $table->timestamps();

            $table->index('customer_id');
            $table->index('user_id');
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            // Reference-compatible columns (schema.sql)
            $table->string('action', 100)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            // AdminLTE ActivityLogger-compatible columns (auth events, LogsActivity trait)
            $table->string('event', 100)->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('customer_id');
            $table->index('user_id');
            $table->index('action');
            $table->index('event');
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index('customer_id');
        });

        Schema::create('impersonation_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('admin_user_id');
            $table->index('customer_user_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_tokens');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('cron_logs');
        Schema::dropIfExists('emails');
        Schema::dropIfExists('email_queue');
        Schema::dropIfExists('automation_log');
        Schema::dropIfExists('audit_log');
    }
};
