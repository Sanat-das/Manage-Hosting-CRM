<?php

use App\Settings\BrandingSettings;
use App\Support\SettingsPropertySeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SettingsPropertySeeder::seedMissing([BrandingSettings::class]);
    }

    public function down(): void
    {
    }
};
