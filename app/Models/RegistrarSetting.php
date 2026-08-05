<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Key/value configuration store for registrar API connections.
 *
 * The `registrar_settings` table is a generic per-registrar KV store
 * (registrar + setting_key unique). Recognised keys (written by the admin
 * registrar settings UI):
 *   - api_endpoint : base URL of the registrar API
 *   - api_key      : secret credential (never rendered in full)
 *   - test_mode    : '1'|'0' — sandbox mode for the registrar API
 *   - enabled      : '1'|'0' — whether the registrar connection is active
 */
#[Fillable(['registrar', 'setting_key', 'setting_value'])]
class RegistrarSetting extends Model
{
    protected $table = 'registrar_settings';

    /**
     * Read a single setting value for a registrar.
     */
    public static function get(string $registrar, string $key, mixed $default = null): mixed
    {
        $row = static::where('registrar', $registrar)->where('setting_key', $key)->first();

        return $row?->setting_value ?? $default;
    }

    /**
     * Read all settings for a registrar as key => value.
     */
    public static function allFor(string $registrar): array
    {
        return static::where('registrar', $registrar)
            ->pluck('setting_value', 'setting_key')
            ->all();
    }

    /**
     * Upsert a batch of settings for a registrar.
     */
    public static function setMany(string $registrar, array $settings): void
    {
        foreach ($settings as $key => $value) {
            static::updateOrCreate(
                ['registrar' => $registrar, 'setting_key' => $key],
                ['setting_value' => is_array($value) ? json_encode($value) : (string) $value],
            );
        }
    }

    /**
     * Distinct registrar names currently configured.
     */
    public static function registrars(): array
    {
        return static::query()
            ->select('registrar')
            ->distinct()
            ->orderBy('registrar')
            ->pluck('registrar')
            ->all();
    }

    /**
     * Mask a secret for display (e.g. API keys).
     */
    public static function mask(string $value): string
    {
        $len = strlen($value);

        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return substr($value, 0, 4).str_repeat('•', 6).substr($value, -4);
    }
}
