<?php

use App\Settings\BillingSettings;
use App\Support\SettingsPropertySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoice seller + bank details, added to BillingSettings (group: billing)
     * as typed nullable-string properties.
     */
    public function up(): void
    {
        SettingsPropertySeeder::seedMissing([BillingSettings::class]);
    }

    public function down(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');

        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->where('group', BillingSettings::group())
            ->whereIn('name', [
                'seller_name',
                'seller_address',
                'seller_gstin',
                'seller_phone',
                'seller_email',
                'bank_name',
                'bank_account_holder',
                'bank_account_no',
                'bank_ifsc',
            ])
            ->delete();
    }
};
