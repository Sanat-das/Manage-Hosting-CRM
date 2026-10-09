<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table): void {
            $table->string('log_key', 36)->nullable()->after('id')->index();
            $table->string('from_email')->nullable()->after('to_email');
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            $table->json('payload')->nullable()->after('body');
            $table->index(['status', 'created_at']);
        });

        // 'sent' was the schema default; the job had to override it on every
        // insert, and a crash before the update would read as delivered.
        Schema::table('emails', function (Blueprint $table): void {
            $table->enum('status', ['sent', 'failed', 'queued'])->default('queued')->change();
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table): void {
            $table->enum('status', ['sent', 'failed', 'queued'])->default('sent')->change();
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['log_key']);
            $table->dropColumn(['log_key', 'from_email', 'attempts', 'payload']);
        });
    }
};
