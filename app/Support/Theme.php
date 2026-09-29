<?php

namespace App\Support;

/**
 * Light / dark / auto colour-scheme preference.
 *
 * Only the browser can evaluate "auto" — it needs `prefers-color-scheme`, which
 * the server cannot see. So the server's job here is narrow: mirror an explicit
 * choice from the `mh_theme` cookie into `<html data-bs-theme>` so the correct
 * palette is present in the first byte of markup. "auto" resolves to null and
 * the pre-paint script in partials/head.blade.php decides before first paint.
 *
 * The cookie is written by resources/js/adminlte.js alongside the existing
 * localStorage entry; localStorage stays authoritative for instant in-page
 * switches, the cookie exists purely so PHP can render the right attribute.
 */
class Theme
{
    /** Cookie holding the visitor's explicit preference: light | dark | auto. */
    public const COOKIE = 'mh_theme';

    public const PREFERENCES = ['light', 'dark', 'auto'];

    public static function preference(): string
    {
        // An explicit config value pins the palette for every visitor; only
        // `null` defers to them (config/adminlte.php: "null = respect system /
        // user toggle"). Honour it here so this resolver never contradicts the
        // documented contract it replaced.
        $forced = config('adminlte.layout_dark_mode');

        if (in_array($forced, ['light', 'dark'], true)) {
            return $forced;
        }

        $value = request()->cookie(self::COOKIE);

        return is_string($value) && in_array($value, self::PREFERENCES, true) ? $value : 'auto';
    }

    /**
     * Palette for the server-rendered <html> attribute. Null means "no explicit
     * choice", leaving the client to resolve Auto pre-paint.
     */
    public static function resolved(): ?string
    {
        $preference = self::preference();

        return $preference === 'auto' ? null : $preference;
    }

    public static function isDark(): bool
    {
        return self::resolved() === 'dark';
    }
}
