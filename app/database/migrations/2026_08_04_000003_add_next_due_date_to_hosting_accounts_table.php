<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosting_accounts', function (Blueprint $table) {
            // RecurringBillingCommand queries `next_due_date` on hosting
            // accounts to generate renewal invoices, but the column only
            // existed on `domains` — every scheduled run crashed with
            // "Unknown column 'next_due_date'". Mirrors the domains column.
            $table->date('next_due_date')->nullable()->after('status');
            $table->index('next_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('hosting_accounts', function (Blueprint $table) {
            $table->dropIndex(['next_due_date']);
            $table->dropColumn('next_due_date');
        });
    }
};
