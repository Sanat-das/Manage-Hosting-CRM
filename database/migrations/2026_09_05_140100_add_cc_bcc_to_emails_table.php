<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the Cc/Bcc a message actually went out with.
 *
 * The `emails` log stored `to_email` alone, so a ticket reply copied to three
 * people looked identical to one sent to a single recipient — there was no way
 * to answer "who received this?" from the log. It also made `SendEmail`'s retry
 * de-duplication ambiguous: it matches on recipient + subject + body, which
 * could not tell two sends apart when only the Cc differed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            // Comma-separated, matching how `to_email` already stores a list.
            $table->string('cc_emails', 1000)->nullable()->after('to_email');
            $table->string('bcc_emails', 1000)->nullable()->after('cc_emails');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn(['cc_emails', 'bcc_emails']);
        });
    }
};
