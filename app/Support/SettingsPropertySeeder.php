<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionProperty;
use Spatie\LaravelSettings\Settings;

/**
 * Creates the settings_properties row for any declared-but-unseeded property.
 *
 * spatie refuses to save a settings class when ANY of its properties has no row
 * in the repository, so adding a property to a class without seeding its row
 * breaks saving that whole group — not just the new field
 * ("Tried saving settings '…', and the following properties were missing: …").
 * Migrations call this after adding properties.
 *
 * Two details matter and are easy to get wrong:
 *
 *  1. Values come from REFLECTION, never from app($class). Resolving a settings
 *     class caches an instance whose SettingsConfig has recorded every unseeded
 *     property as "default value loaded", and spatie counts those as missing on
 *     the next save. A seeder that resolved the class would poison the very
 *     container it is trying to fix — visible in tests, which run migrations
 *     and then the suite in one process.
 *  2. Any instance cached by an EARLIER migration is forgotten afterwards, so
 *     the next resolution sees the rows this pass inserted.
 */
final class SettingsPropertySeeder
{
    /**
     * @param  list<class-string<Settings>>|null  $classes  defaults to every registered settings class
     * @return int number of rows inserted
     */
    public static function seedMissing(?array $classes = null): int
    {
        $table = config('settings.repositories.database.table', 'settings_properties');

        if (! Schema::hasTable($table)) {
            return 0;
        }

        /** @var list<class-string<Settings>> $classes */
        $classes = $classes ?? config('settings.settings', []);
        $inserted = 0;

        foreach ($classes as $class) {
            try {
                $inserted += self::seedClass($class, $table);
            } catch (\Throwable $e) {
                // A settings class that cannot be read must never block
                // migrations; the rest of the seed still runs.
                report($e);
            }

            // Drop anything an earlier migration resolved, so the stale
            // "missing property" bookkeeping goes with it.
            app()->forgetInstance($class);
        }

        app()->forgetScopedInstances();

        return $inserted;
    }

    /**
     * @param  class-string<Settings>  $class
     */
    private static function seedClass(string $class, string $table): int
    {
        $group = $class::group();
        $casts = $class::casts();
        $reflection = new ReflectionClass($class);
        $defaults = $reflection->getDefaultProperties();

        $existing = DB::table($table)
            ->where('group', $group)
            ->pluck('name')
            ->all();

        $inserted = 0;

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if ($property->isStatic() || in_array($name, $existing, true)) {
                continue;
            }

            $value = $defaults[$name] ?? null;
            $cast = $casts[$name] ?? null;

            if (is_string($cast)) {
                $cast = new $cast;
            }

            DB::table($table)->insert([
                'group' => $group,
                'name' => $name,
                'payload' => json_encode($cast ? $cast->set($value) : $value),
                'locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $inserted++;
        }

        return $inserted;
    }
}
