<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_log', function (Blueprint $table): void {
            $table->index('created_at');
        });

        Schema::table('domain_sync_log', function (Blueprint $table): void {
            $table->index('created_at');
        });

        Schema::table('invoice_pdf_log', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('module_log', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });

        Schema::table('domain_sync_log', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });

        Schema::table('invoice_pdf_log', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
    }
};
