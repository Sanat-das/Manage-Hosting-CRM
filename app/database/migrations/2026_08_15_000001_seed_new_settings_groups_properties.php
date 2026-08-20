<?php

use App\Settings\AnalyticsSettings;
use App\Settings\AutomationSettings;
use App\Settings\CatalogSettings;
use App\Settings\CronSettings;
use App\Settings\DomainSettings;
use App\Settings\HostingSettings;
use App\Settings\IntegrationSettings;
use App\Settings\InventorySettings;
use App\Settings\IpamSettings;
use App\Settings\ProductSettings;
use App\Settings\RoleSettings;
use App\Settings\UserSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelSettings\Settings;

return new class extends Migration
{
    /**
     * New T4.2 typed settings groups. spatie only persists a settings instance
     * when every declared property already exists in the repository; any
     * property resolved from its reflection default is treated as "missing" on
     * save. This migration pre-seeds the defaults for the newly added groups so
     * their first save() succeeds, mirroring the T4.1 copy migration.
     *
     * @var array<class-string<Settings>>
     */
    private const SETTINGS_CLASSES = [
        DomainSettings::class,
        IntegrationSettings::class,
        HostingSettings::class,
        IpamSettings::class,
        InventorySettings::class,
        CatalogSettings::class,
        ProductSettings::class,
        AnalyticsSettings::class,
        AutomationSettings::class,
        CronSettings::class,
        RoleSettings::class,
        UserSettings::class,
    ];

    public function up(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');

        if (! Schema::hasTable($table)) {
            return;
        }

        foreach (self::SETTINGS_CLASSES as $class) {
            try {
                $settings = app($class);
            } catch (Throwable $e) {
                // A settings class that cannot be resolved should never block
                // migrations; skip it so the rest of the seed still runs.
                continue;
            }

            $group = $settings->group();
            $config = $settings->settingsConfig();

            foreach ($config->getReflectedProperties() as $name => $property) {
                if ($property->isStatic()) {
                    continue;
                }

                $exists = DB::table($table)
                    ->where('group', $group)
                    ->where('name', $name)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $value = $settings->{$name};
                $cast = $config->getCast($name);
                $payload = $cast ? $cast->set($value) : $value;

                DB::table($table)->insert([
                    'group' => $group,
                    'name' => $name,
                    'payload' => json_encode($payload),
                    'locked' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Seed data only; nothing to undo.
    }
};
