<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email threading columns for support tickets.
     *
     * `updated_at` is the fix for a live bug, not a nicety: `ticket_replies`
     * was created with `created_at` only while App\Models\TicketReply keeps
     * Eloquent's default timestamps, so every TicketReply::create() — client
     * reply, admin reply, API reply, and now inbound mail — died with
     * "Unknown column 'updated_at' in 'INSERT INTO'". Same fix as
     * 2026_08_04_000004 did for `emails`.
     *
     * `email_message_id` holds the RFC 5322 Message-ID (without angle
     * brackets) of the mail this reply produced, and is UNIQUE so a message
     * fetched twice cannot become two replies. `email_in_reply_to` records
     * what an inbound mail was answering, which is how a reply is matched back
     * to its ticket when the subject tag has been mangled. 191 chars keeps the
     * unique index inside MariaDB's index limit; longer ids are truncated at
     * the service boundary.
     */
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable()->after('created_at');
            $table->string('email_message_id', 191)->nullable()->after('updated_at');
            $table->string('email_in_reply_to', 191)->nullable()->after('email_message_id');

            $table->unique('email_message_id');
            $table->index('email_in_reply_to');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->dropUnique(['email_message_id']);
            $table->dropIndex(['email_in_reply_to']);
            $table->dropColumn(['updated_at', 'email_message_id', 'email_in_reply_to']);
        });
    }
};
