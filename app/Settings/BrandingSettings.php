<?php

namespace App\Settings;

use Illuminate\Validation\Rule;
use Spatie\LaravelSettings\Settings;

/**
 * Branding settings (group: branding) — HostVexa identity.
 */
class BrandingSettings extends Settings
{
    public string $branding_app_name = 'HostVexa';

    public string $branding_tagline = 'Hosting Management Platform';

    public string $branding_logo_path = '';

    public string $branding_favicon_path = '';

    public string $branding_primary_color = '#0EA5E9';

    public string $branding_sidebar_theme = '';

    public string $branding_footer_text = '© {year} HostVexa. All rights reserved.';

    public string $branding_accent_color = '#6366F1';

    public static function group(): string
    {
        return 'branding';
    }

    public static function rules(): array
    {
        return [
            'branding_app_name' => ['required', 'string', 'max:50'],
            'branding_tagline' => ['nullable', 'string', 'max:100'],
            'branding_logo_path' => ['nullable', 'string', 'max:500'],
            'branding_favicon_path' => ['nullable', 'string', 'max:500'],
            'branding_primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'branding_sidebar_theme' => ['nullable', 'string', Rule::in(['', 'dark', 'light'])],
            'branding_footer_text' => ['nullable', 'string', 'max:255'],
            'branding_accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
