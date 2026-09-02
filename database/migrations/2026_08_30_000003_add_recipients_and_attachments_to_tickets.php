<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outlook-style rework (.omo/plans/ticket-outlook-style-rework.md, Task 2):
     * per-message To/Cc/Bcc and file/image attachments.
     *
     * `to`/`cc`/`bcc` are JSON arrays of plain email strings, nullable — most
     * existing replies (and every reply until Task 5 wires the compose UI to
     * write them) have none, and `TicketMailService::recipientFor()` keeps
     * being the fallback when a reply has no explicit `to`.
     *
     * `ticket_attachments` mirrors one file per row rather than a JSON list
     * on `ticket_replies`, so a single large file doesn't force loading every
     * attachment's bytes-adjacent metadata just to check a ticket has any.
     * `content_id` is nullable and only meaningful when `is_inline` is true —
     * it is the `cid:` reference an inline image's HTML `<img>` tag uses, one
     * per file, unique per reply (not globally) since each message builds its
     * own MIME structure.
     */
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->json('to')->nullable()->after('raw_source');
            $table->json('cc')->nullable()->after('to');
            $table->json('bcc')->nullable()->after('cc');
        });

        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_reply_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 50)->default('local');
            $table->string('path', 500);
            $table->string('filename', 255);
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->boolean('is_inline')->default(false);
            $table->string('content_id', 191)->nullable();
            $table->timestamps();

            $table->index(['ticket_reply_id', 'is_inline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');

        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->dropColumn(['to', 'cc', 'bcc']);
        });
    }
};
