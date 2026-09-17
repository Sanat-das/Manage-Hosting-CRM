<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master kill switch for the customer-facing chat widget.
 *
 * Default ON: an upgrade must not silently take down a channel customers are
 * already using. This is the deliberate exception to the settings page's
 * everything-off-by-default convention — defaulting it OFF would switch the
 * widget off on every existing install the moment this deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->boolean('customer_chat_enabled')->default(true)->after('send_transcript_on_close');
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn('customer_chat_enabled');
        });
    }
};
