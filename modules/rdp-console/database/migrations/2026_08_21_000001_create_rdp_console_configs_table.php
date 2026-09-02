<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdp_console_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hosting_account_id')->constrained('hosting_accounts')->cascadeOnDelete();
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(3389);
            $table->string('username')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('domain')->nullable();
            $table->timestamps();
            $table->unique(['hosting_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rdp_console_configs');
    }
};
