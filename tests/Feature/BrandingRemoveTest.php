<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\BrandingSettings;
use App\Support\AppSettings;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingRemoveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        app()->forgetScopedInstances();
        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    public function test_remove_logo_checkbox_deletes_file_and_falls_back_to_default(): void
    {
        $this->seedBrandingFile('branding/test-logo.svg', 'branding/test-favicon.svg');

        // Precondition: file exists on fake public disk and Branding resolves to it.
        Storage::disk('public')->assertExists('branding/test-logo.svg');
        Storage::disk('public')->assertExists('branding/test-favicon.svg');
        $this->assertSame('branding/test-logo.svg', app(BrandingSettings::class)->branding_logo_path);
        $this->assertStringContainsString('branding/test-logo.svg', Branding::logoUrl());

        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'branding',
                'settings' => [],
                'remove_branding_logo' => '1',
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'branding']))
            ->assertSessionHasNoErrors();

        // Storage cleaned up.
        Storage::disk('public')->assertMissing('branding/test-logo.svg');
        // Favicon not touched when only logo remove sent.
        Storage::disk('public')->assertExists('branding/test-favicon.svg');

        // Spatie singleton is stale after POST — refresh.
        app()->forgetScopedInstances();
        $this->clearAppSettingsCache();

        $this->assertSame('', app(BrandingSettings::class)->branding_logo_path, 'DB branding_logo_path must be cleared to empty string');
        $this->assertDatabaseHas('settings_properties', [
            'group' => 'branding',
            'name' => 'branding_logo_path',
            'payload' => json_encode(''),
        ]);

        // Branding helper falls back to default asset on next load.
        $logoUrl = Branding::logoUrl();
        $this->assertStringContainsString(Branding::DEFAULT_LOGO, $logoUrl, 'logoUrl must fall back to DEFAULT_LOGO after clear');
        $this->assertSame('', Branding::logoPath());
        $this->assertStringContainsString(Branding::DEFAULT_LOGO, $logoUrl);

        // Next GET — blade renders default placeholder, not the old stored path.
        $html = $this->actingAsSettingsAdmin()->get(route('admin.settings.index', ['tab' => 'branding']))->getContent();
        $this->assertStringContainsString(Branding::DEFAULT_LOGO.' (default)', $html);
        $this->assertStringNotContainsString('branding/test-logo.svg', $html);
    }

    public function test_remove_favicon_checkbox_deletes_file_and_falls_back_to_default(): void
    {
        $this->seedBrandingFile('branding/test-logo.svg', 'branding/test-favicon-two.svg');

        Storage::disk('public')->assertExists('branding/test-favicon-two.svg');

        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'branding',
                'settings' => [],
                'remove_branding_favicon' => 1,
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'branding']))
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing('branding/test-favicon-two.svg');
        Storage::disk('public')->assertExists('branding/test-logo.svg');

        app()->forgetScopedInstances();
        $this->clearAppSettingsCache();

        $this->assertSame('', app(BrandingSettings::class)->branding_favicon_path);
        $this->assertStringContainsString(Branding::DEFAULT_FAVICON, Branding::faviconUrl());
    }

    public function test_remove_via_nested_settings_key_also_clears(): void
    {
        $this->seedBrandingFile('branding/nested-logo.svg', null);

        Storage::disk('public')->assertExists('branding/nested-logo.svg');

        // Blade could send settings[remove_branding_logo] — controller handles both.
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'branding',
                'settings' => [
                    'remove_branding_logo' => '1',
                ],
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'branding']))
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing('branding/nested-logo.svg');

        app()->forgetScopedInstances();
        $this->clearAppSettingsCache();

        $this->assertSame('', app(BrandingSettings::class)->branding_logo_path);
    }

    public function test_remove_with_no_existing_file_is_noop(): void
    {
        // Ensure clean state — no file on disk, DB empty (default '' from seeder).
        $this->assertSame('', app(BrandingSettings::class)->branding_logo_path);
        Storage::disk('public')->assertMissing('branding/ghost.svg');

        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'branding',
                'settings' => [],
                'remove_branding_logo' => '1',
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'branding']))
            ->assertSessionHasNoErrors();

        app()->forgetScopedInstances();
        $this->clearAppSettingsCache();

        $this->assertSame('', app(BrandingSettings::class)->branding_logo_path);
        $this->assertStringContainsString(Branding::DEFAULT_LOGO, Branding::logoUrl());
    }

    public function test_upload_and_remove_together_upload_wins_and_file_is_saved(): void
    {
        $this->seedBrandingFile('branding/old-logo.svg', null);

        Storage::disk('public')->assertExists('branding/old-logo.svg');
        $oldPath = app(BrandingSettings::class)->branding_logo_path;
        $this->assertSame('branding/old-logo.svg', $oldPath);

        $newFile = UploadedFile::fake()->image('new-logo.png', 200, 200);

        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'branding',
                'settings' => [],
                // Both at once — JS normally unchecks, but backend must also prefer upload.
                'remove_branding_logo' => '1',
                'branding_logo' => $newFile,
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'branding']))
            ->assertSessionHasNoErrors();

        app()->forgetScopedInstances();
        $this->clearAppSettingsCache();

        $newPath = app(BrandingSettings::class)->branding_logo_path;

        $this->assertNotSame('', $newPath, 'Upload must win — path must not be cleared to empty');
        $this->assertNotSame($oldPath, $newPath, 'New upload must replace old path');
        $this->assertStringStartsWith('branding/', $newPath, 'Stored path must be on public branding disk');

        // Storage: old cleaned up, new exists.
        Storage::disk('public')->assertMissing('branding/old-logo.svg');
        Storage::disk('public')->assertExists($newPath);

        // DB payload is the new path, not empty.
        $this->assertDatabaseHas('settings_properties', [
            'group' => 'branding',
            'name' => 'branding_logo_path',
            'payload' => json_encode($newPath),
        ]);

        // Branding helper no longer falls back — logoUrl points to new file.
        $logoUrl = Branding::logoUrl();
        $this->assertStringNotContainsString(Branding::DEFAULT_LOGO, $logoUrl, 'logoUrl must not fall back when upload wins');
        $this->assertSame($newPath, Branding::logoPath());
        $this->assertStringContainsString('storage', $logoUrl);

        // Next load: blade shows new stored path, not default placeholder.
        $html = $this->actingAsSettingsAdmin()->get(route('admin.settings.index', ['tab' => 'branding']))->getContent();
        $this->assertStringContainsString($newPath, $html);
        $this->assertStringNotContainsString(Branding::DEFAULT_LOGO.' (default)', $html);
    }

    public function test_favicon_upload_and_remove_together_upload_wins(): void
    {
        $this->seedBrandingFile(null, 'branding/old-favicon.svg');

        Storage::disk('public')->assertExists('branding/old-favicon.svg');
        $oldPath = app(BrandingSettings::class)->branding_favicon_path;

        $newFile = UploadedFile::fake()->image('new-favicon.png', 64, 64);

        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'branding',
                'settings' => [],
                'remove_branding_favicon' => '1',
                'branding_favicon' => $newFile,
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'branding']))
            ->assertSessionHasNoErrors();

        app()->forgetScopedInstances();
        $this->clearAppSettingsCache();

        $newPath = app(BrandingSettings::class)->branding_favicon_path;

        $this->assertNotSame('', $newPath);
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing('branding/old-favicon.svg');
        Storage::disk('public')->assertExists($newPath);
        $this->assertStringNotContainsString(Branding::DEFAULT_FAVICON, Branding::faviconUrl());
    }

    private function seedBrandingFile(?string $logoPath, ?string $faviconPath): void
    {
        $settings = app(BrandingSettings::class);

        $payload = [];
        if ($logoPath !== null) {
            $payload['branding_logo_path'] = $logoPath;
            Storage::disk('public')->put($logoPath, '<svg>fake</svg>');
        }
        if ($faviconPath !== null) {
            $payload['branding_favicon_path'] = $faviconPath;
            Storage::disk('public')->put($faviconPath, 'fake-favicon');
        }

        if ($payload !== []) {
            $settings->fill($payload);
            $settings->save();

            app()->forgetScopedInstances();
            $this->clearAppSettingsCache();
        }
    }

    private function clearAppSettingsCache(): void
    {
        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    private function actingAsSettingsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['settings.view', 'settings.manage'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
