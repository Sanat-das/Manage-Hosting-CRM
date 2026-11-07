<?php

use App\Support\Notifications\NotificationMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the invoice.paid / customer / mail matrix cell (enabled) added to the
 * EventCatalogue, then flush the matrix cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_event_settings')->insertOrIgnore([
            'event_key' => 'invoice.paid',
            'audience' => 'customer',
            'channel' => 'mail',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        NotificationMatrix::flush();
    }

    public function down(): void
    {
        DB::table('notification_event_settings')
            ->where('event_key', 'invoice.paid')
            ->where('audience', 'customer')
            ->where('channel', 'mail')
            ->delete();

        NotificationMatrix::flush();
    }
};
