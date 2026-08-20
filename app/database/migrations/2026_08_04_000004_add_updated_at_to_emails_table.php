<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            // `EmailLog` uses Eloquent's default timestamps, but the table only
            // defined `created_at` — every `SendEmail` job crashed with
            // "Unknown column 'updated_at'". Add the missing half so
            // EmailLog::create()/update() succeed.
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });
    }
};
