<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * First slice of the Outlook-style rework (.omo/plans/ticket-outlook-style-rework.md,
     * Task 1): HTML body + raw MIME source, both nullable and inbound-only for
     * now. `message` stays the plain-text column everything else (quoting,
     * matching, notifications) already relies on — `html_body` is
     * presentation-only until the compose UI (Task 3/4) writes it for
     * outbound replies too.
     *
     * `raw_source` is the full inbound message (headers + body) captured at
     * fetch time for the "show original" view (Task 6). It is never built for
     * outbound mail here — reconstructing that from what we already sent is
     * a display-layer concern for that later task, not a write at send time.
     */
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->longText('html_body')->nullable()->after('message');
            $table->longText('raw_source')->nullable()->after('from_email');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->dropColumn(['html_body', 'raw_source']);
        });
    }
};
