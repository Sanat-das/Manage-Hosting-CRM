<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the once-per-renewal-cycle reminder bookkeeping columns to orders.
 *
 * `renewal_reminder_for` records the renewal date the armed reminder belongs
 * to; `renewal_reminder_sent_at` records when that reminder went out. Together
 * they let the reminder pass skip a cycle it has already reminded for instead
 * of mailing the customer on every run. Both stay nullable because existing
 * orders have no reminder armed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('renewal_reminder_for')->nullable()->after('last_billing_date');
            $table->timestamp('renewal_reminder_sent_at')->nullable()->after('renewal_reminder_for');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['renewal_reminder_for', 'renewal_reminder_sent_at']);
        });
    }
};
