<?php

use App\Support\SettingsPropertySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Policy for inbound mail that matches no existing ticket.
     *
     * `allow_new_tickets` is per department because the answer legitimately
     * differs per desk — sales@ wants strangers to be able to write in, while
     * a billing queue may want replies only. It mirrors WHMCS's per-department
     * "Pipe Replies Only", inverted so the default is the permissive one the
     * feature was asked for.
     *
     * The two matching global settings (imap_auto_create_customers,
     * imap_default_department) are declared on EmailSettings and seeded here;
     * spatie refuses to save a group with an unseeded property.
     */
    public function up(): void
    {
        Schema::table('ticket_departments', function (Blueprint $table) {
            $table->boolean('allow_new_tickets')->default(true)->after('enabled');
        });

        SettingsPropertySeeder::seedMissing();
    }

    public function down(): void
    {
        Schema::table('ticket_departments', function (Blueprint $table) {
            $table->dropColumn('allow_new_tickets');
        });
    }
};
