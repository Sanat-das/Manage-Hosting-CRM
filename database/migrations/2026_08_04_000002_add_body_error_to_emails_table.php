<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            // `SendEmail` job persists the raw body and any send error to the
            // audit log. Neither existed, so both were silently dropped by the
            // model's mass-assignment guard (no fillable) AND the schema.
            $table->text('body')->nullable()->after('subject');
            $table->text('error')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn(['body', 'error']);
        });
    }
};