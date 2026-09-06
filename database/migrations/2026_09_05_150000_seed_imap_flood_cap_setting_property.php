<?php

use App\Support\SettingsPropertySeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Seed the row for the new `imap_max_new_tickets_per_hour` property on
     * EmailSettings.
     *
     * spatie refuses to save a settings class when ANY declared property is
     * missing from the repository, so adding the property without seeding its
     * row would break saving the whole Email tab — not just this field. Same
     * shape as 2026_08_28_000003; the seeder is idempotent and covers every
     * registered class, not only the one that changed.
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
