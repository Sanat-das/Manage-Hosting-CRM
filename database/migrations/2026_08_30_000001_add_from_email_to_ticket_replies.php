<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The raw From address of an inbound reply, distinct from `user_id`.
     *
     * `TicketMailService` used to always email the ticket's customer account
     * login address, ignoring the address that actually wrote in — a
     * customer's account email and the mailbox they message support from are
     * often different (a personal Gmail vs. the account's login email, a
     * secondary contact, etc.). This records what the mail parser saw so a
     * staff reply can default to answering that address instead of guessing
     * from the account.
     *
     * Null for replies that did not arrive by mail (client portal, admin UI,
     * API) — those have no better source than the account email already.
     */
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->string('from_email', 191)->nullable()->after('email_in_reply_to');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->dropColumn('from_email');
        });
    }
};
