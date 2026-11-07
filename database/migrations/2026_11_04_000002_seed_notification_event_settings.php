<?php

use App\Support\Notifications\EventCatalogue;
use App\Support\Notifications\NotificationMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed notification_event_settings from the EventCatalogue defaults, then
 * honour any of the legacy global kill-switches the admin may have disabled
 * on the Settings page (notify_new_tickets, notify_domain_expiry,
 * notify_overdue_invoices) by disabling every row of the matching event.
 */
return new class extends Migration
{
    /** Mapping from legacy settings key to event key. */
    private const LEGACY_KEY_MAP = [
        'notify_new_tickets' => 'ticket.new',
        'notify_domain_expiry' => 'domain.expiring',
        'notify_overdue_invoices' => 'invoice.overdue',
    ];

    public function up(): void
    {
        foreach (EventCatalogue::events() as $event) {
            foreach ($event['defaults'] as $combo => $enabled) {
                [$channel, $audience] = explode('.', $combo, 2);

                DB::table('notification_event_settings')->insertOrIgnore([
                    'event_key' => $event['key'],
                    'audience' => $audience,
                    'channel' => $channel,
                    'enabled' => $enabled,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (self::LEGACY_KEY_MAP as $legacyKey => $eventKey) {
            $value = DB::table('settings')->where('setting_key', $legacyKey)->value('setting_value');

            if ($value !== null && in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', ''], true)) {
                DB::table('notification_event_settings')
                    ->where('event_key', $eventKey)
                    ->update(['enabled' => false, 'updated_at' => now()]);
            }
        }

        NotificationMatrix::flush();
    }

    public function down(): void
    {
        // Rows owned by the matrix table; safe to clear on rollback of the feature.
        DB::table('notification_event_settings')->whereIn('event_key', array_column(EventCatalogue::events(), 'key'))->delete();
        NotificationMatrix::flush();
    }
};
