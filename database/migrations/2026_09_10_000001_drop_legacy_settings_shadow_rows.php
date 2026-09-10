<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the legacy `settings` rows that shadow a typed settings_properties
 * property, and the one typed property that shadowed a legacy row.
 *
 * Why this exists: 2026_07_30_120020_create_config_tables seeded 17 rows into
 * `settings`, and 2026_08_12_000001 COPIED them into settings_properties
 * without removing the originals. Since then the admin settings page has
 * written only the typed copy, so the legacy rows have been frozen at their
 * install-time values — and drifted:
 *
 *   company_name   legacy 'Hosting Company'  typed 'Test Co'
 *   timezone       legacy 'Asia/Kolkata'     typed 'Africa/Abidjan'
 *   smtp_host      legacy ''                 typed 'mail.wwinnovators.com'
 *
 * Any code doing Setting::where('setting_key', …) therefore read a stale value
 * while the UI showed the live one. Two callers did exactly that
 * (GstTaxService::calculateTax on tax_rate, TicketService::nextTicketNumber on
 * ticket_prefix); both now read through AppSettings. Deleting the rows is what
 * stops the next one being written.
 *
 * `ticket_next_number` goes the OTHER way: the counter must stay a legacy row
 * because TicketService allocates it under lockForUpdate(), which spatie's
 * repository cannot do. Its typed property has been removed from
 * SupportSettings, so the now-orphan settings_properties row is dropped here —
 * after carrying its value across if it happened to be ahead.
 *
 * Safety: a legacy row is only deleted when the typed row that supersedes it
 * actually exists, and down() rebuilds the legacy rows from the typed payloads,
 * so a rollback restores current values rather than install-time ones.
 */
return new class extends Migration
{
    /**
     * Legacy `settings` key => the settings_properties group that now owns it.
     *
     * Hardcoded rather than derived from AppSettings::TYPED_KEYS: a migration
     * must keep doing the same thing when that constant changes later.
     */
    private const SHADOWED = [
        'company_name' => 'general',
        'company_email' => 'general',
        'company_phone' => 'general',
        'company_address' => 'general',
        'timezone' => 'general',
        'date_format' => 'general',
        'currency' => 'billing',
        'tax_rate' => 'billing',
        'invoice_prefix' => 'billing',
        'invoice_next_number' => 'billing',
        'ticket_prefix' => 'support',
        'smtp_host' => 'email',
        'smtp_port' => 'email',
        'smtp_username' => 'email',
        'smtp_password' => 'email',
        'smtp_encryption' => 'email',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('settings_properties')) {
            return;
        }

        $this->reclaimTicketCounter();

        foreach (self::SHADOWED as $key => $group) {
            $typedExists = DB::table('settings_properties')
                ->where('group', $group)
                ->where('name', $key)
                ->exists();

            // No typed row means nothing supersedes this legacy value — leaving
            // it is strictly safer than deleting the only copy.
            if (! $typedExists) {
                continue;
            }

            DB::table('settings')->where('setting_key', $key)->delete();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('settings_properties')) {
            return;
        }

        foreach (self::SHADOWED as $key => $group) {
            $payload = DB::table('settings_properties')
                ->where('group', $group)
                ->where('name', $key)
                ->value('payload');

            if ($payload === null) {
                continue;
            }

            $value = json_decode((string) $payload, true);

            // Objects/arrays have no legacy string form; skip rather than store
            // a JSON blob a legacy reader would choke on.
            if (is_array($value)) {
                continue;
            }

            DB::table('settings')->updateOrInsert(
                ['setting_key' => $key],
                [
                    'setting_value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                    'group' => $group,
                    'updated_at' => now(),
                ],
            );
        }

        // Restore the typed counter property's row so SupportSettings can be
        // saved again if the class is rolled back with the schema.
        DB::table('settings_properties')->updateOrInsert(
            ['group' => 'support', 'name' => 'ticket_next_number'],
            [
                'payload' => json_encode((int) (DB::table('settings')
                    ->where('setting_key', 'ticket_next_number')
                    ->value('setting_value') ?? 1)),
                'locked' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * Keep the highest of the two ticket counters, then drop the typed one.
     *
     * nextTicketNumber() walks forward past taken numbers, so a counter that is
     * behind self-heals — but an admin who typed a higher number on the
     * settings page meant it, and that intent only exists in the typed row.
     */
    private function reclaimTicketCounter(): void
    {
        $typedPayload = DB::table('settings_properties')
            ->where('group', 'support')
            ->where('name', 'ticket_next_number')
            ->value('payload');

        if ($typedPayload === null) {
            return;
        }

        $typed = (int) json_decode((string) $typedPayload, true);
        $legacy = (int) (DB::table('settings')
            ->where('setting_key', 'ticket_next_number')
            ->value('setting_value') ?? 0);

        $winner = max(1, $typed, $legacy);

        DB::table('settings')->updateOrInsert(
            ['setting_key' => 'ticket_next_number'],
            ['setting_value' => (string) $winner, 'group' => 'support', 'updated_at' => now()],
        );

        DB::table('settings_properties')
            ->where('group', 'support')
            ->where('name', 'ticket_next_number')
            ->delete();
    }
};
