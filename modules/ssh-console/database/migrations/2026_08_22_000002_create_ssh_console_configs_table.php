<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account SSH connection settings for the ssh-console module web terminal.
 * One row per hosting account (unique hosting_account_id). Secrets are stored
 * encrypted via model casts and are only decrypted server-side when a
 * terminal session is opened — they are never rendered into HTML.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_console_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hosting_account_id')->constrained('hosting_accounts')->cascadeOnDelete();
            $table->string('host')->nullable();
            $table->integer('port')->default(22);
            $table->string('username')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->text('private_key_encrypted')->nullable();
            $table->text('passphrase_encrypted')->nullable();
            $table->timestamps();
            $table->unique(['hosting_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_console_configs');
    }
};
