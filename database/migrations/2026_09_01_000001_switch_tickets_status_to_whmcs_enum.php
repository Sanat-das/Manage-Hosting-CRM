<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switches tickets.status from the local open/pending/resolved/closed enum
 * to the WHMCS-style open/answered/customer_reply/on_hold/in_progress/closed
 * set. `on_hold` and `in_progress` are new manual-only staff states (see
 * TicketService::setStatus); `answered`/`customer_reply` replace the old
 * automatic pending/open-on-reply pair.
 *
 * Widen-migrate-narrow so no in-flight row is ever outside the enum's
 * allowed values: pending -> answered, resolved -> closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('status', ['open', 'pending', 'resolved', 'closed', 'answered', 'customer_reply', 'on_hold', 'in_progress'])
                ->default('open')
                ->change();
        });

        DB::table('tickets')->where('status', 'pending')->update(['status' => 'answered']);
        DB::table('tickets')->where('status', 'resolved')->update(['status' => 'closed']);

        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('status', ['open', 'answered', 'customer_reply', 'on_hold', 'in_progress', 'closed'])
                ->default('open')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('status', ['open', 'pending', 'resolved', 'closed', 'answered', 'customer_reply', 'on_hold', 'in_progress'])
                ->default('open')
                ->change();
        });

        DB::table('tickets')->where('status', 'answered')->update(['status' => 'pending']);
        DB::table('tickets')->whereIn('status', ['customer_reply', 'on_hold', 'in_progress'])->update(['status' => 'open']);

        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('status', ['open', 'pending', 'resolved', 'closed'])
                ->default('open')
                ->change();
        });
    }
};
