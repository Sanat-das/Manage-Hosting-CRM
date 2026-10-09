<?php

use App\Settings\LogRetentionSettings;
use App\Support\SettingsPropertySeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SettingsPropertySeeder::seedMissing([LogRetentionSettings::class]);
    }

    public function down(): void {}
};
