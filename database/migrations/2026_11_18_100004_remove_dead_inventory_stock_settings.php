<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the six dead `inventory_*` stock settings and retires the
 * `inventory` settings group entirely.
 *
 * The knobs were declared on InventorySettings, mapped in
 * AppSettings::TYPED_KEYS and rendered on the Inventory settings tab, but no
 * production code ever read them — no low-stock monitor, restock job or
 * notification ever consulted these values. A setting that controls no
 * behaviour is dead weight offering a control with no effect, so the typed
 * class, its back-compat key mapping, the form pane and the tab are removed
 * alongside this migration; the persisted settings_properties rows are
 * dropped here.
 *
 * Mirrors 2026_10_05_170000_remove_dead_ipam_scan_setting: down() re-creates
 * the rows it removed, with the typed class defaults.
 */
return new class extends Migration
{
    private const GROUP = 'inventory';

    /**
     * @var array<string, array{payload: string}>
     */
    private const ROWS = [
        'inventory_track_stock' => 'false',
        'inventory_low_stock_threshold' => '5',
        'inventory_auto_restock' => 'false',
        'inventory_restock_min_quantity' => '10',
        'inventory_notify_low_stock' => 'true',
        'inventory_stock_unit' => '"units"',
    ];

    public function up(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');

        if (Schema::hasTable($table)) {
            DB::table($table)
                ->where('group', self::GROUP)
                ->delete();
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->whereIn('setting_key', array_keys(self::ROWS))
                ->delete();
        }
    }

    public function down(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');
        $now = now();

        if (Schema::hasTable($table)) {
            foreach (self::ROWS as $name => $payload) {
                DB::table($table)->updateOrInsert(
                    ['group' => self::GROUP, 'name' => $name],
                    ['payload' => $payload, 'locked' => false, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }
};
