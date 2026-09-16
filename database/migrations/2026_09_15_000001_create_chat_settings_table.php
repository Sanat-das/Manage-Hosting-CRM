<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chat's own settings: one row, like `gst_settings`.
 *
 * Deliberately NOT a spatie settings class and NOT a row in the legacy
 * `settings` table. The first needs a matching `settings_properties` row seeded
 * before the class can be saved at all (and the updater never seeds), and the
 * second is the table whose blank rows already shadow real values elsewhere in
 * this app. A dedicated single-row table is the shape `gst_settings` proved:
 * typed columns, ordinary validation, one writer.
 *
 * Every default here is chosen so this migration changes NO behaviour on an
 * existing install:
 *
 *   enforce_office_hours = false      chat stays open 24/7 until an admin says
 *                                     otherwise, exactly as it is today
 *   send_transcript_on_close = false  closing a chat must not start emailing
 *                                     customers the moment this deploys
 *
 * The two features arrive switched off and are opted into from
 * /admin/chat/settings. `offline_form_enabled` defaults ON because it only ever
 * applies while the chat is closed, which cannot happen until office hours are
 * enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_settings', function (Blueprint $table) {
            $table->id();

            // --- office hours ---------------------------------------------
            $table->boolean('enforce_office_hours')->default(false);

            // Blank means "use config('app.timezone')". Stored rather than
            // derived because the hours an admin types are local to the support
            // team, and the app timezone may be UTC on a server nobody sits at.
            $table->string('timezone')->default('');

            // "Open" can mean more than the clock: a Tuesday afternoon with
            // every operator marked Away is not open in any sense the customer
            // cares about. Off by default — an install with no one using the
            // availability control would otherwise look permanently closed.
            $table->boolean('require_available_operator')->default(false);

            $table->text('closed_message')->nullable();

            // --- offline capture ------------------------------------------
            $table->boolean('offline_form_enabled')->default(true);

            // Which ticket department an out-of-hours message opens under.
            // Null means the first enabled department, resolved at send time so
            // renaming or disabling a department cannot strand this column.
            $table->string('offline_ticket_department')->nullable();

            // --- transcripts ----------------------------------------------
            $table->boolean('send_transcript_on_close')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_settings');
    }
};
