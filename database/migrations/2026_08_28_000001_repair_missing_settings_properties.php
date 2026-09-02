<?php

use App\Support\SettingsPropertySeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Repair pass for missing settings_properties rows.
     *
     * spatie refuses to save a settings class when any declared property has no
     * row in the repository (MissingSettings, e.g. "integration ... missing:
     * cpanel_host, resellerclub_api_key"). The T4.2 seed migration
     * (2026_08_15_000001) covers the groups that existed when it ran, but rows
     * that were never seeded — or later removed — leave the group unsaveable.
     *
     * This re-seeds any missing property, for every class registered in
     * config('settings.settings'), from the class's own reflection default. It
     * is idempotent: existing rows are left untouched, so it is safe to copy
     * forward whenever a new property is added to a settings class.
     *
     * The work lives in SettingsPropertySeeder, which reads defaults through
     * reflection rather than resolving the settings classes. Resolving them
     * here cached instances that still believed the rows were missing, and
     * spatie counts those "default value loaded" properties against the next
     * save — so this migration used to poison the container it was fixing,
     * which showed up as a MissingSettings failure in the first test of a run.
     */
    public function up(): void
    {
        SettingsPropertySeeder::seedMissing();
    }

    public function down(): void
    {
        // Seed data only; nothing to undo.
    }
};
