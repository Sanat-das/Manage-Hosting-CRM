<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the dead `ipam_scan_interval_minutes` setting.
 *
 * The knob was declared on IpamSettings, mapped in AppSettings::TYPED_KEYS and
 * rendered on the IPAM settings tab, but no scheduler entry, job or command
 * ever reads it — nothing scans on a configured interval. A setting that
 * controls no behaviour is dead weight offering a control with no effect, so
 * the typed property, its back-compat key mapping and the form control are
 * removed alongside this migration; the persisted settings_properties row is
 * dropped here.
 *
 * Mirrors 2026_10_02_000003_remove_dead_duplicate_settings: down() re-creates
 * the row it removed.
 */
return new class extends Migration
{
    private const GROUP = 'ipam';

    private const NAME = 'ipam_scan_interval_minutes';

    public function up(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');

        if (Schema::hasTable($table)) {
            DB::table($table)
                ->where('group', self::GROUP)
                ->where('name', self::NAME)
                ->delete();
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->where('setting_key', self::NAME)
                ->delete();
        }
    }

    public function down(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');
        $now = now();

        if (Schema::hasTable($table)) {
            DB::table($table)->updateOrInsert(
                ['group' => self::GROUP, 'name' => self::NAME],
                ['payload' => '60', 'locked' => false, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->updateOrInsert(
                ['setting_key' => self::NAME],
                ['setting_value' => '', 'group' => self::GROUP, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
};
