<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lightweight read helper for the settings table.
 * Values are cached for the duration of a single request.
 */
class AppSettings
{
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            self::$cache = DB::table('settings')
                ->pluck('setting_value', 'setting_key')
                ->toArray();
        }

        return self::$cache[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(
            self::get($key, $default ? 'yes' : 'no'),
            FILTER_VALIDATE_BOOLEAN
        );
    }
}
