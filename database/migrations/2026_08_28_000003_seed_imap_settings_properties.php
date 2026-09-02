<?php

use App\Support\SettingsPropertySeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Seed the rows for the new imap_* properties on EmailSettings.
     *
     * spatie refuses to save a settings class when ANY declared property is
     * missing from the repository, so adding properties without seeding their
     * rows breaks saving the whole Email group. Copy this migration forward
     * whenever a settings class gains a property — the seeder is idempotent
     * and covers every registered class, not just the one that changed.
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
