<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

/**
 * The Echo client is wired into the build and onto the panel pages.
 *
 * A chat UI that subscribes to nothing looks identical to one whose websocket
 * is merely quiet, so the wiring is asserted rather than assumed.
 */
class EchoBootstrapTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    public function test_echo_module_initialises_a_reverb_connection(): void
    {
        $source = file_get_contents(resource_path('js/echo.js'));

        $this->assertStringContainsString('new Echo(', $source);
        $this->assertStringContainsString("broadcaster: 'reverb'", $source);
        $this->assertStringContainsString('VITE_REVERB_APP_KEY', $source);
        $this->assertStringContainsString('VITE_REVERB_HOST', $source);
        $this->assertStringContainsString('VITE_REVERB_SCHEME', $source);

        // pusher-js must come from the bundle, not a CDN.
        $this->assertStringContainsString("from 'pusher-js'", $source);
        $this->assertStringNotContainsString('cdn.', $source);
    }

    public function test_missing_reverb_config_degrades_instead_of_throwing(): void
    {
        $source = file_get_contents(resource_path('js/echo.js'));

        // The guard clause, not an unguarded `new Echo`, has to come first:
        // an uncaught throw here would take the whole admin bundle down on any
        // install that has not configured Reverb.
        $this->assertLessThan(
            strpos($source, 'new Echo('),
            strpos($source, 'if (!appKey)'),
            'echo.js must check for a missing app key before constructing Echo.',
        );

        $this->assertStringContainsString('catch (error)', $source);
        $this->assertStringContainsString('realtime.enabled = false', $source);
    }

    public function test_echo_is_a_vite_entry_point(): void
    {
        $this->assertStringContainsString(
            "'resources/js/echo.js'",
            file_get_contents(base_path('vite.config.js')),
            'echo.js is not listed as a Vite input, so it is never built.',
        );
    }

    public function test_panel_head_loads_the_echo_bundle(): void
    {
        $this->assertStringContainsString(
            'resources/js/echo.js',
            file_get_contents(resource_path('views/vendor/adminlte/partials/head.blade.php')),
            'The panel <head> does not @vite echo.js, so no page ever loads it.',
        );
    }

    public function test_built_manifest_exposes_the_echo_chunk(): void
    {
        $manifestPath = public_path('build/manifest.json');

        if (! file_exists($manifestPath)) {
            $this->markTestSkipped('No Vite build present — run `npm run build`.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! isset($manifest['resources/js/echo.js'])) {
            $this->markTestSkipped(
                'The committed Vite build predates echo.js — run `npm run build` and commit public/build.'
            );
        }

        $this->assertArrayHasKey('file', $manifest['resources/js/echo.js']);

        $chunk = public_path('build/'.$manifest['resources/js/echo.js']['file']);
        $this->assertFileExists($chunk);
        $this->assertStringContainsString('reverb', file_get_contents($chunk));
    }

    public function test_a_rendered_panel_page_actually_references_the_echo_chunk(): void
    {
        $manifestPath = public_path('build/manifest.json');
        $manifest = file_exists($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : [];

        if (! isset($manifest['resources/js/echo.js']['file'])) {
            $this->markTestSkipped(
                'The committed Vite build predates echo.js — run `npm run build` and commit public/build.'
            );
        }

        // Compiled Blade can look right and still render nothing useful, so the
        // assertion is against the HTML the browser receives.
        $this->actingAs($this->panelUserWithPermissions('chat.view'))
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertSee($manifest['resources/js/echo.js']['file'], false);
    }
}
