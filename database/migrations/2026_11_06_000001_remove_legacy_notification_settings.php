<?php

use App\Support\AppSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the legacy `notify_*` notification-toggle rows from the `settings`
 * table.
 *
 * The Notification Settings matrix page (/admin/notification-settings)
 * supersedes the three flat yes/no toggles that used to render on the
 * settings page's "Notifications" tab. The tab, its blade pane and the
 * AppSettings section/legacy-key mapping are removed alongside this
 * migration; the rows are dead weight with no remaining readers.
 *
 * Mirrors 2026_10_02_000003_remove_dead_duplicate_settings: down() re-creates
 * the rows it removed.
 */
return new class extends Migration
{
    private const KEYS = [
        'notify_overdue_invoices',
        'notify_domain_expiry',
        'notify_new_tickets',
    ];

    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->whereIn('setting_key', self::KEYS)
                ->delete();
        }

        AppSettings::flush();
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            $now = now();

            foreach (self::KEYS as $key) {
                DB::table('settings')->updateOrInsert(
                    ['setting_key' => $key],
                    ['setting_value' => 'yes', 'group' => 'general', 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        AppSettings::flush();
    }
};
