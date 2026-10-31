<?php

use App\Settings\BillingSettings;
use App\Support\SettingsPropertySeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * BillingSettings::$renewal_reminder_days was added to the class without a
     * matching settings_properties row, which makes every save of the billing
     * group throw MissingSettings. Databases migrated before the property was
     * declared never got the row, so seed it here.
     *
     * No down(): removing the row would re-break saving the group, and a later
     * re-run would overwrite the configured value with the class default.
     */
    public function up(): void
    {
        SettingsPropertySeeder::seedMissing([BillingSettings::class]);
    }

    public function down(): void {}
};
