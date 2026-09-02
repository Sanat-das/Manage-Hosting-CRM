<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail of web SSH terminal sessions for the ssh-console module: who
 * opened a shell on which account, from which IP, and how the session ended.
 * status is 'opened' while active, then 'closed' or 'failed' (error holds the
 * failure message). No keystrokes or output are ever persisted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_console_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hosting_account_id')->constrained('hosting_accounts')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 16)->default('opened'); // 'opened' | 'closed' | 'failed'
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['hosting_account_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_console_sessions');
    }
};
