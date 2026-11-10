<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `hosting_suspend_after_days` setting.
 *
 * The grace period a service may stay unpaid before it is suspended for
 * non-payment. `hosting_suspend_on_overdue` (already declared, never read)
 * switches the behaviour off; this says how long the customer gets.
 *
 * spatie only persists a typed settings group when every declared property
 * exists in the repository, so the new property needs its own row before the
 * first save() of HostingSettings — the same pattern the T4.2 seeding
 * migration used.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');

        if (! Schema::hasTable($table)) {
            return;
        }

        $exists = DB::table($table)
            ->where('group', 'hosting')
            ->where('name', 'hosting_suspend_after_days')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table($table)->insert([
            'group' => 'hosting',
            'name' => 'hosting_suspend_after_days',
            'payload' => json_encode(7),
            'locked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');

        DB::table($table)
            ->where('group', 'hosting')
            ->where('name', 'hosting_suspend_after_days')
            ->delete();
    }
};
