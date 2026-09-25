<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_accounts', function (Blueprint $table) {
            $table->string('guest_username', 64)->nullable()->after('password_encrypted');
            $table->text('guest_password_encrypted')->nullable()->after('guest_username');
        });
    }

    public function down(): void
    {
        Schema::table('panel_accounts', function (Blueprint $table) {
            $table->dropColumn(['guest_username', 'guest_password_encrypted']);
        });
    }
};
