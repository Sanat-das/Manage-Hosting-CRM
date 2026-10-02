<?php

use App\Settings\GeneralSettings;
use App\Support\SettingsPropertySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seller identity moves to GeneralSettings (company_* keys, already the
     * invoice PDF's seller source); company GSTIN is the one missing field.
     * The duplicate seller_* rows are dropped from the billing group, leaving
     * bank details as the only billing-card settings.
     */
    public function up(): void
    {
        SettingsPropertySeeder::seedMissing([GeneralSettings::class]);

        $table = config('settings.repositories.database.table', 'settings_properties');

        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->where('group', 'billing')
            ->whereIn('name', [
                'seller_name',
                'seller_address',
                'seller_gstin',
                'seller_phone',
                'seller_email',
            ])
            ->delete();
    }

    public function down(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');

        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->where('group', 'general')
            ->where('name', 'company_gstin')
            ->delete();

        $now = now();

        foreach (['seller_name', 'seller_address', 'seller_gstin', 'seller_phone', 'seller_email'] as $name) {
            DB::table($table)->insert([
                'group' => 'billing',
                'name' => $name,
                'payload' => 'null',
                'locked' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
