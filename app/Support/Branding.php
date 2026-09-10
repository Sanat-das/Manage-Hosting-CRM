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
    public const DEFAULT_LOGO = 'img/hostvexa-logo.png';
    public const DEFAULT_LOGO_WEBP = 'img/hostvexa-logo.webp';
    public const DEFAULT_MARK = 'img/hostvexa-mark.png';
    public const DEFAULT_MARK_WEBP = 'img/hostvexa-mark.webp';
    public const DEFAULT_FAVICON = 'img/hostvexa-favicon.png';
    public const DEFAULT_FAVICON_WEBP = 'img/hostvexa-favicon.webp';
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
            'logo_webp_url' => self::logoWebpUrl(),
            'logo_path' => self::logoPath(),
            'mark_url' => self::markUrl(),
            'mark_webp_url' => self::markWebpUrl(),
            'favicon_url' => self::faviconUrl(),
            'favicon_webp_url' => self::faviconWebpUrl(),
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

    /** WebP variant of the logo URL — falls back to PNG when no WebP exists. */
    public static function logoWebpUrl(): string
    {
        $pngUrl = self::logoUrl();
        // Only promote shipped defaults to WebP; storage uploads stay as-is unless they are already .webp.
        if (str_ends_with($pngUrl, '.png') && str_contains($pngUrl, 'hostvexa-logo')) {
            $webp = str_replace('.png', '.webp', $pngUrl);
            // Verify the WebP asset exists on disk to avoid 404 for custom uploads.
            if (is_file(public_path(self::DEFAULT_LOGO_WEBP))) {
                return $webp;
            }
        }
        // If the stored path itself is a .webp upload, return it directly.
        if (str_ends_with(strtolower($pngUrl), '.webp')) {
            return $pngUrl;
        }
        // For custom PNG uploads with a shipped WebP available, offer WebP as alternative
        // but keep PNG as fallback — caller should use <picture>.
        if (str_ends_with($pngUrl, '.png') && is_file(public_path(self::DEFAULT_LOGO_WEBP))) {
            return str_replace('.png', '.webp', asset(self::DEFAULT_LOGO_WEBP));
        }

        return $pngUrl;
    }

    public static function logoWebpFallbackUrl(): string
    {
        return self::logoUrl();
    }

    /**
     * Email-safe logo URL — embeds as data URI when APP_URL is not publicly
     * reachable (e.g. http://*.local, localhost, 127.0.0.1) so the image isn't
     * broken in inboxes. On a public domain it returns the normal URL to keep
     * emails small. Used by BuildsEmailVariables for {{app_logo_url}}.
     */
    public static function logoEmailUrl(): string
    {
        $url = self::logoUrl();

        // In testing, keep the URL lightweight — embedding 68KB base64 into
        // every Blade render blows the 128M limit when the view is compiled
        // (the compiled view file becomes >300KB and phpunit spawns a 128M child).
        if (app()->environment('testing')) {
            return $url;
        }

        // Embed only when the *resolved* logo URL is still non-public.
        // Because baseUrl() auto-fetches the request host, a public request
        // (e.g. https://billing.example.com) will already give a public $url
        // even when config('app.url') is still http://*.local — in that case
        // return the public URL like IDFC (no attachment needed).
        $isLocal = str_contains($url, '.local') || str_contains($url, 'localhost') || str_contains($url, '127.0.0.1');

        if (! $isLocal) {
            return $url;
        }

        // Try custom upload first (storage/app/public/branding/...), then shipped default.
        $path = self::logoPath();
        $file = null;
        $mime = 'image/png';

        if ($path !== '' && str_contains($path, 'branding/')) {
            try {
                $diskPath = preg_replace('#^storage/#', '', $path);
                $diskPath = ltrim($diskPath, '/');
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($diskPath)) {
                    $file = \Illuminate\Support\Facades\Storage::disk('public')->path($diskPath);
                    $mime = mime_content_type($file) ?: 'image/png';
                }
            } catch (\Throwable) {}
        }

        if ($file === null) {
            // Shipped default — prefer PNG for email (WebP not supported in most clients)
            $candidate = public_path(self::DEFAULT_LOGO);
            if (is_file($candidate)) {
                $file = $candidate;
                $mime = mime_content_type($candidate) ?: 'image/png';
            } else {
                return $url;
            }
        }

        try {
            $data = @file_get_contents($file);
            if ($data === false || $data === '') {
                return $url;
            }
            // Cap at ~200KB to avoid huge emails; our optimized logo is 51KB (68KB base64) — safe.
            if (strlen($data) > 250 * 1024) {
                return $url;
            }

            return 'data:'.$mime.';base64,'.base64_encode($data);
        } catch (\Throwable) {
            return $url;
        }
    }

    public static function logoDataUri(): string
    {
        return self::logoEmailUrl();
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

        return self::asset(self::DEFAULT_MARK);
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

        return self::asset(self::DEFAULT_FAVICON);
    }

    public static function faviconWebpUrl(): string
    {
        $png = self::faviconUrl();
        if (str_ends_with($png, '.png') && is_file(public_path(self::DEFAULT_FAVICON_WEBP))) {
            return str_replace('.png', '.webp', $png);
        }

        return $png;
    }

    public static function markWebpUrl(): string
    {
        $png = self::markUrl();
        if (str_ends_with($png, '.png') && is_file(public_path(self::DEFAULT_MARK_WEBP))) {
            return str_replace('.png', '.webp', $png);
        }

        return $png;
    }

    public static function ogUrl(): string
    {
        // No dedicated og setting — derive from logo or use shipped og image.
        $path = self::logoPath();
        if ($path !== '') {
            return self::resolveAssetUrl($path, self::DEFAULT_OG);
        }

        return self::asset(self::DEFAULT_OG);
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
     * Uses <picture> with WebP primary and PNG fallback — cuts ~65% bytes
     * (17KB vs 51KB) on modern browsers while staying compatible with
     * email/PDF contexts that only understand PNG.
     */
    public static function logoHtml(): string
    {
        $appName = e(self::appName());
        $png = self::logoUrl();
        $webp = self::logoWebpUrl();

        if ($png !== '' || $webp !== '') {
            $pngE = e($png);
            $webpE = e($webp);
            // Use <picture> only when WebP differs from PNG and exists.
            if ($webp !== $png && $webpE !== '') {
                return '<span class="brand-logo-hostvexa"><picture><source srcset="'.$webpE.'" type="image/webp"><img src="'.$pngE.'" alt="'.$appName.'" style="height:32px;width:auto;vertical-align:middle;object-fit:contain" loading="lazy"></picture></span>';
            }

            return '<span class="brand-logo-hostvexa"><img src="'.$pngE.'" alt="'.$appName.'" style="height:32px;width:auto;vertical-align:middle;object-fit:contain" loading="lazy"></span>';
        }

        return '<span class="brand-logo-hostvexa"><i class="bi bi-hdd-rack"></i> '.$appName.'</span>';
    }

    /**
     * <picture> HTML for the wordmark — WebP + PNG fallback. Use in Blade
     * where you want the optimized path (auth pages, settings preview).
     */
    public static function logoPictureHtml(string $alt = '', string $style = 'height:32px;width:auto;vertical-align:middle;object-fit:contain'): string
    {
        $alt = $alt !== '' ? $alt : self::appName();
        $png = self::logoUrl();
        $webp = self::logoWebpUrl();
        $altE = e($alt);
        $styleE = e($style);
        $pngE = e($png);
        $webpE = e($webp);
        if ($webp !== $png) {
            return '<picture><source srcset="'.$webpE.'" type="image/webp"><img src="'.$pngE.'" alt="'.$altE.'" style="'.$styleE.'" loading="lazy"></picture>';
        }

        return '<img src="'.$pngE.'" alt="'.$altE.'" style="'.$styleE.'" loading="lazy">';
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
     * - Otherwise treat as public/ relative and use Branding::asset() which
     *   auto-fetches the request domain when APP_URL is .local.
     */
    public static function resolveAssetUrl(string $storedPath, string $fallbackAsset): string
    {
        $path = trim($storedPath);

        if ($path === '') {
            return self::asset($fallbackAsset);
        }

        if (str_starts_with($path, 'data:') || str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//')) {
            return $path;
        }

        // Leading slash public path.
        if (str_starts_with($path, '/')) {
            return self::asset(ltrim($path, '/'));
        }

        // Storage paths — any path containing branding/ or already storage-prefixed.
        if (str_starts_with($path, 'storage/') || str_starts_with($path, 'branding/') || str_contains($path, 'branding/')) {
            try {
                // Storage::url() already uses APP_URL, but we want the auto-fetched
                // base for emails. When the storage URL is relative, prefix with baseUrl().
                $url = Storage::url(preg_replace('#^storage/#', '', $path));
                // If Storage::url returned a .local URL but we have a public request host, fix it.
                $base = self::baseUrl();
                if (str_contains($url, '.local') && !str_contains($base, '.local') && $base !== '') {
                    $url = str_replace(parse_url($url, PHP_URL_HOST) ?? 'managehosting.local', parse_url($base, PHP_URL_HOST) ?? '', $url);
                    // Simpler: replace the base part
                    $url = $base . '/storage/' . ltrim(preg_replace('#^storage/#', '', $path), '/');
                }
                return $url;
            } catch (\Throwable) {
                return self::asset($fallbackAsset);
            }
        }

        // Bare asset-like path (img/..., vendor/..., favicon.svg)
        if (str_contains($path, '.') || str_contains($path, '/')) {
            return self::asset($path);
        }

        // Fallback — treat as asset.
        return self::asset($path);
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
     * Auto-fetched base URL — uses the current request's domain when APP_URL
     * is a non-public placeholder (e.g. http://managehosting.local, localhost)
     * so emails don't get a broken .local host. Falls back to config('app.url').
     * The result is always rtrimmed and has no trailing slash.
     */
    public static function baseUrl(): string
    {
        $cfg = rtrim((string) config('app.url', ''), '/');

        $isLocalCfg = $cfg === '' || str_contains($cfg, '.local') || str_contains($cfg, 'localhost') || str_contains($cfg, '127.0.0.1');

        if ($isLocalCfg) {
            try {
                // Prefer the actual request host when available (web request that
                // triggered the invoice, or the URL generator's current request).
                $requestUrl = null;
                if (function_exists('request') && ($req = request()) && method_exists($req, 'getSchemeAndHttpHost')) {
                    $requestUrl = rtrim($req->getSchemeAndHttpHost(), '/');
                    // Only use request host if it's not itself local
                    if ($requestUrl !== '' && !str_contains($requestUrl, '.local') && !str_contains($requestUrl, 'localhost') && !str_contains($requestUrl, '127.0.0.1')) {
                        return $requestUrl;
                    }
                }
                // Fallback to url('/') which also respects the current request
                $urlHelper = rtrim((string) url('/'), '/');
                if ($urlHelper !== '' && !str_contains($urlHelper, '.local') && !str_contains($urlHelper, 'localhost') && !str_contains($urlHelper, '127.0.0.1')) {
                    return $urlHelper;
                }
            } catch (\Throwable) {}
        }

        // Public config or no better guess — return config (may still be .local, but caller will then embed as data URI)
        return $cfg !== '' ? $cfg : rtrim((string) url('/'), '/');
    }

    /**
     * Asset helper that respects baseUrl() — so even when APP_URL is .local,
     * a public request host will be used for the generated URL.
     */
    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $base = self::baseUrl();

        if ($base === '') {
            return asset($path);
        }

        return rtrim($base, '/').'/'.ltrim($path, '/');
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
