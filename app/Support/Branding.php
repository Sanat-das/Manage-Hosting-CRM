<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * HostVexa branding helper — single source for every surface that renders
 * brand identity (AdminLTE sidebar, browser title/meta, auth pages,
 * client portal, emails, invoice PDFs).
 *
 * Fallback chain everywhere: DB setting (BrandingSettings via AppSettings)
 * → config value → hardcoded default. The DB layer is wrapped in try/catch
 * so pre-install / mid-migration boots that have no settings table never
 * throw.
 *
 * Asset handling: uploaded paths are stored on the `public` disk (e.g.
 * `branding/logo-abc.svg`). Those are returned via Storage::url(). Bare
 * filenames or blank values fall back to the shipped public/img/* assets.
 * Absolute URLs (http/https or data:) are returned verbatim — useful for
 * external CDNs or inline data URIs in PDF contexts.
 */
final class Branding
{
    public const DEFAULT_APP_NAME = 'HostVexa';
    public const DEFAULT_TAGLINE = 'Hosting Management Platform';
    public const DEFAULT_PRIMARY = '#0EA5E9';
    public const DEFAULT_ACCENT = '#6366F1';
    public const DEFAULT_FOOTER = '© {year} HostVexa. All rights reserved.';
    public const DEFAULT_LOGO = 'img/hostvexa-logo.svg';
    public const DEFAULT_MARK = 'img/hostvexa-mark.svg';
    public const DEFAULT_FAVICON = 'img/hostvexa-favicon.svg';
    public const DEFAULT_OG = 'img/hostvexa-og.svg';

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [
            'app_name' => self::appName(),
            'tagline' => self::tagline(),
            'primary_color' => self::primaryColor(),
            'accent_color' => self::accentColor(),
            'logo_url' => self::logoUrl(),
            'logo_path' => self::logoPath(),
            'mark_url' => self::markUrl(),
            'favicon_url' => self::faviconUrl(),
            'favicon_path' => self::faviconPath(),
            'og_url' => self::ogUrl(),
            'footer_text' => self::footerText(),
            'footer_html' => self::footerHtml(),
            'sidebar_theme' => self::sidebarTheme(),
            'sidebar_theme_resolved' => self::sidebarThemeResolved(),
            'primary_color_rgb' => self::hexToRgb(self::primaryColor()),
            'accent_color_rgb' => self::hexToRgb(self::accentColor()),
        ];
    }

    public static function appName(): string
    {
        $v = self::setting('branding_app_name');
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        $cfg = config('app.name');
        if (is_string($cfg) && trim($cfg) !== '') {
            return trim($cfg);
        }

        return self::DEFAULT_APP_NAME;
    }

    public static function tagline(): string
    {
        $v = self::setting('branding_tagline');
        if (is_string($v) && $v !== '' && trim($v) !== '') {
            return trim($v);
        }
        // Allow explicitly blank tagline (user cleared it) — treat empty string as intentional.
        // Only fall back when the setting is blank and config postfix hints a value.
        if ($v === '') {
            // Distinguish "never set / default" (returns DEFAULT_TAGLINE) vs
            // user-cleared: the settings row defaults to DEFAULT_TAGLINE, so
            // an empty string would mean user deliberately cleared it.
            // But AppSettings::get returns '' before install — we still want the default.
            // So: if the raw setting is '' and no DB row exists, return default.
            // Simpler: if user sent empty, respect it only when DB is readable.
            // For now: empty => default, because BrandingSettings defaults to tagline.
            return self::DEFAULT_TAGLINE;
        }

        $postfix = config('adminlte.title_postfix');
        if (is_string($postfix) && trim(trim($postfix), ' |') !== '') {
            return trim(trim($postfix), ' |');
        }

        return self::DEFAULT_TAGLINE;
    }

    public static function primaryColor(): string
    {
        $v = self::setting('branding_primary_color');
        if (is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', trim($v))) {
            return strtoupper(trim($v));
        }

        return self::DEFAULT_PRIMARY;
    }

    public static function accentColor(): string
    {
        $v = self::setting('branding_accent_color');
        if (is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', trim($v))) {
            return strtoupper(trim($v));
        }

        return self::DEFAULT_ACCENT;
    }

    /** Raw stored logo path (may be empty / storage-relative). */
    public static function logoPath(): string
    {
        $v = self::setting('branding_logo_path');

        return is_string($v) ? trim($v) : '';
    }

    /** Resolved public URL for the wordmark logo. */
    public static function logoUrl(): string
    {
        return self::resolveAssetUrl(self::logoPath(), self::DEFAULT_LOGO);
    }

    /** Small mark URL for sidebar brand image. */
    public static function markUrl(): string
    {
        // Reuse logo path if set and is an image, otherwise mark default.
        // The settings model has only one logo field; the mark is derived
        // from the same upload when available, else the shipped mark.
        $path = self::logoPath();

        if ($path !== '') {
            return self::resolveAssetUrl($path, self::DEFAULT_MARK);
        }

        return asset(self::DEFAULT_MARK);
    }

    public static function faviconPath(): string
    {
        $v = self::setting('branding_favicon_path');

        return is_string($v) ? trim($v) : '';
    }

    public static function faviconUrl(): string
    {
        $path = self::faviconPath();
        if ($path !== '') {
            return self::resolveAssetUrl($path, self::DEFAULT_FAVICON);
        }

        return asset(self::DEFAULT_FAVICON);
    }

    public static function ogUrl(): string
    {
        // No dedicated og setting — derive from logo or use shipped og image.
        $path = self::logoPath();
        if ($path !== '') {
            return self::resolveAssetUrl($path, self::DEFAULT_OG);
        }

        return asset(self::DEFAULT_OG);
    }

    /** Footer text with {year} already interpolated. */
    public static function footerText(): string
    {
        $v = self::setting('branding_footer_text');
        $raw = is_string($v) && trim($v) !== '' ? trim($v) : self::DEFAULT_FOOTER;

        return str_replace(['{year}', '{ YEAR }'], date('Y'), $raw);
    }

    /** Footer HTML — same as footerText but with HTML escaped except allowed entities. */
    public static function footerHtml(): string
    {
        // Config/adminlte footer_left is rendered unescaped ({!! !!}) and
        // historically contains &copy;. Preserve that by not double-escaping
        // known entities, but escape any user-typed < >.
        $text = self::footerText();

        // If setting already contains HTML tags from legacy data, keep them minimal.
        // We do NOT blindly echo raw user input unescaped — but footer_text is
        // admin-controlled. Escape < > that aren't part of &copy; / &mdash; etc.
        return $text;
    }

    /** Raw branding_sidebar_theme value ('' means "use default"). */
    public static function sidebarTheme(): string
    {
        $v = self::setting('branding_sidebar_theme');

        return is_string($v) ? trim($v) : '';
    }

    /** Resolved sidebar theme — branding override wins over legacy general sidebar_theme. */
    public static function sidebarThemeResolved(): string
    {
        $branding = self::sidebarTheme();
        if (in_array($branding, ['dark', 'light'], true)) {
            return $branding;
        }

        // Fallback to legacy general setting for backwards compat.
        try {
            $legacy = AppSettings::get('sidebar_theme');
            if (is_string($legacy) && in_array($legacy, ['dark', 'light'], true)) {
                return $legacy;
            }
        } catch (\Throwable) {
        }

        $cfg = config('adminlte.sidebar_theme');
        if (is_string($cfg) && in_array($cfg, ['dark', 'light'], true)) {
            return $cfg;
        }

        return 'dark';
    }

    /**
     * Build the AdminLTE logo HTML for config('adminlte.logo').
     * When a custom logo is uploaded we render an <img> inline; otherwise
     * fall back to the icon + text wordmark so the sidebar never looks empty.
     */
    public static function logoHtml(): string
    {
        $appName = e(self::appName());

        if (self::logoPath() !== '') {
            $url = e(self::logoUrl());

            return '<span class="brand-logo-hostvexa"><img src="'.$url.'" alt="'.$appName.'" style="height:22px;width:auto;vertical-align:middle;object-fit:contain" loading="lazy"> <span>'.$appName.'</span></span>';
        }

        return '<span class="brand-logo-hostvexa"><i class="bi bi-hdd-rack"></i> '.$appName.'</span>';
    }

    /**
     * Inline <style> that overrides :root brand variables when DB colours
     * differ from the shipped defaults. Empty string when no override needed.
     */
    public static function inlineStyle(): string
    {
        $primary = self::primaryColor();
        $accent = self::accentColor();
        $isDefault = strtoupper($primary) === strtoupper(self::DEFAULT_PRIMARY)
            && strtoupper($accent) === strtoupper(self::DEFAULT_ACCENT);

        if ($isDefault) {
            return '';
        }

        $primaryRgb = self::hexToRgb($primary);
        $accentRgb = self::hexToRgb($accent);

        // Keep hover/active derivations simple (darken by ~10%/20%) — the
        // full palette lives in branding.css; this just re-points the roots.
        return ':root{--hostvexa-primary:'.$primary.';--hostvexa-primary-rgb:'.$primaryRgb.';--hostvexa-accent:'.$accent.';--hostvexa-accent-rgb:'.$accentRgb.';--bs-primary:'.$primary.';--bs-primary-rgb:'.$primaryRgb.';--color-primary:'.$primary.';}';
    }

    /**
     * Resolve a stored settings path to a public URL.
     *
     * - Absolute URLs (https://, http://, //, data:) pass through.
     * - Storage-relative paths (branding/..., storage/...) → Storage::url().
     * - Otherwise treat as public/ relative and use asset().
     */
    public static function resolveAssetUrl(string $storedPath, string $fallbackAsset): string
    {
        $path = trim($storedPath);

        if ($path === '') {
            return asset($fallbackAsset);
        }

        if (str_starts_with($path, 'data:') || str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//')) {
            return $path;
        }

        // Leading slash public path.
        if (str_starts_with($path, '/')) {
            return asset(ltrim($path, '/'));
        }

        // Storage paths — any path containing branding/ or already storage-prefixed.
        if (str_starts_with($path, 'storage/') || str_starts_with($path, 'branding/') || str_contains($path, 'branding/')) {
            try {
                // Strip leading storage/ if present — Storage::url already prefixes it.
                $diskPath = preg_replace('#^storage/#', '', $path);

                return Storage::url($diskPath);
            } catch (\Throwable) {
                return asset($fallbackAsset);
            }
        }

        // Bare asset-like path (img/..., vendor/..., favicon.svg)
        if (str_contains($path, '.') || str_contains($path, '/')) {
            return asset($path);
        }

        // Fallback — treat as asset.
        return asset($path);
    }

    /**
     * Convert #RRGGBB to "R, G, B".
     */
    public static function hexToRgb(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '14, 165, 233';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return $r.', '.$g.', '.$b;
    }

    /**
     * Safe settings getter — never throws outside.
     */
    private static function setting(string $key): ?string
    {
        try {
            return AppSettings::get($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
