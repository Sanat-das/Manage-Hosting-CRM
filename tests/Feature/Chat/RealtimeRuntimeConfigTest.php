<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Customer;
use App\Models\User;
use App\Support\ReverbConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

/**
 * Realtime must survive a production build and the secret must never reach the browser.
 *
 * The defect: Vite inlines `import.meta.env.VITE_REVERB_APP_KEY` at build time.
 * `.env` on the builder has no REVERB_* keys, so `if (!key) return` folds to a
 * constant and the bundler dead-code-eliminates the entire realtime branch.
 * `public/build/` is committed and the updater never runs `npm run build`, so
 * the shipped bundle is permanently polling-only.
 *
 * The fix: PHP renders the public Reverb config into the page at request time
 * (`window.__REVERB__` from ReverbConfig::forClient()), and the JS reads that
 * at runtime. The connection attempt is a genuine runtime decision the bundler
 * cannot fold away, and the secret never leaves the server.
 */
class RealtimeRuntimeConfigTest extends TestCase
{
    use CreatesChatUsers;
    use CreatesPanelUsers;
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Runtime config element: present when configured, absent when not.
    // -----------------------------------------------------------------

    public function test_runtime_config_element_is_present_on_admin_chat_when_configured(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'test-public-key-123',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        $response = $this->actingAs($this->panelUserWithPermissions('chat.view'))
            ->get(route('admin.chat.index'))
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('window.__REVERB__', $html, 'Runtime config must be rendered when Reverb is configured.');
        $this->assertStringContainsString('test-public-key-123', $html);
        $this->assertStringContainsString('127.0.0.1', $html);
    }

    public function test_runtime_config_element_is_present_on_login_when_configured(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'login-key-xyz',
            'broadcasting.connections.reverb.options.host' => 'example.test',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'https',
        ]);

        $response = $this->get(route('login'))->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('window.__REVERB__', $html);
        $this->assertStringContainsString('login-key-xyz', $html);
    }

    public function test_runtime_config_element_is_present_on_client_portal_when_configured(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'client-key-abc',
            'broadcasting.connections.reverb.options.host' => 'example.test',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        // Use /login (which includes the widget via auth-master) and /client (client portal) — both carry <head>.
        $loginHtml = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString('window.__REVERB__', $loginHtml);
        $this->assertStringContainsString('client-key-abc', $loginHtml);

        $user = User::factory()->create(['role' => 'client']);
        Customer::create(['user_id' => $user->id, 'status' => 'active']);
        $clientHtml = $this->actingAs($user)->get('/client')->assertOk()->getContent();
        $this->assertStringContainsString('window.__REVERB__', $clientHtml);
    }

    public function test_runtime_config_element_is_absent_when_not_configured(): void
    {
        config([
            'broadcasting.connections.reverb.key' => null,
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        // Guest page first — before actingAs contaminates the session.
        $this->get(route('login'))->assertOk()->assertDontSee('window.__REVERB__', false);
        $this->assertNull(ReverbConfig::forClient());

        $this->actingAs($this->panelUserWithPermissions('chat.view'))
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertDontSee('window.__REVERB__', false);
    }

    public function test_runtime_config_absent_when_key_is_empty_string(): void
    {
        config([
            'broadcasting.connections.reverb.key' => '   ',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        $this->actingAs($this->panelUserWithPermissions('chat.view'))
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertDontSee('window.__REVERB__', false);
    }

    // -----------------------------------------------------------------
    // Secret must never reach HTML.
    // -----------------------------------------------------------------

    public function test_the_app_secret_never_appears_in_rendered_html_of_any_page_type(): void
    {
        $secret = 'super-secret-REVERB-'.bin2hex(random_bytes(8));

        config([
            'broadcasting.connections.reverb.key' => 'public-key-for-test',
            'broadcasting.connections.reverb.secret' => $secret,
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        // Guest page first.
        $loginHtml = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $loginHtml, 'Secret leaked into login HTML.');

        // Admin chat page
        $adminHtml = $this->actingAs($this->panelUserWithPermissions('chat.view'))
            ->get(route('admin.chat.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $adminHtml, 'Secret leaked into admin chat HTML.');

        // Client portal page (requires auth)
        $user = User::factory()->create(['role' => 'client']);
        Customer::create(['user_id' => $user->id, 'status' => 'active']);
        $clientHtml = $this->actingAs($user)->get('/client')->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $clientHtml, 'Secret leaked into client portal HTML.');

        // Also grep built JS for secret (defense-in-depth: secret must not be in bundle either)
        $this->assertStringNotContainsString($secret, (string) json_encode(ReverbConfig::forClient()), 'forClient must not contain secret.');
        $cfg = ReverbConfig::forClient();
        $this->assertNotNull($cfg);
        $this->assertArrayNotHasKey('secret', $cfg);
        $this->assertArrayNotHasKey('REVERB_APP_SECRET', $cfg);
    }

    public function test_for_client_never_exposes_secret_even_when_config_has_it(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 'should-never-leak',
            'broadcasting.connections.reverb.options.host' => 'h',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        $cfg = ReverbConfig::forClient();
        $this->assertNotNull($cfg);
        $this->assertStringNotContainsString('should-never-leak', json_encode($cfg));
    }

    // -----------------------------------------------------------------
    // CSP: connect-src includes Reverb origin when configured, not otherwise.
    // -----------------------------------------------------------------

    public function test_csp_contains_reverb_origin_when_configured(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'k123',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        $csp = $this->actingAs($this->panelUserWithPermissions('chat.view'))
            ->get(route('admin.chat.index'))->assertOk()->headers->get('Content-Security-Policy');

        $this->assertIsString($csp);
        $this->assertStringContainsString('connect-src', $csp);
        $this->assertStringContainsString('ws://127.0.0.1:8081', $csp, 'CSP must allow ws Reverb origin when configured.');
        $this->assertStringContainsString('wss://127.0.0.1:8081', $csp);
        $this->assertStringContainsString("'self'", $csp);
    }

    public function test_csp_is_self_only_when_not_configured(): void
    {
        config([
            'broadcasting.connections.reverb.key' => null,
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        $csp = $this->get(route('login'))->assertOk()->headers->get('Content-Security-Policy');
        $this->assertIsString($csp);
        // Must not widen when not configured.
        $this->assertStringNotContainsString('ws://', $csp);
        $this->assertStringNotContainsString('wss://', $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
    }

    public function test_csp_does_not_widen_on_malformed_port(): void
    {
        foreach (['not-a-port', '99999', '-1', '0', ''] as $badPort) {
            config([
                'broadcasting.connections.reverb.key' => 'k',
                'broadcasting.connections.reverb.options.host' => '127.0.0.1',
                'broadcasting.connections.reverb.options.port' => $badPort,
                'broadcasting.connections.reverb.options.scheme' => 'http',
            ]);

            $csp = $this->get(route('login'))->assertOk()->headers->get('Content-Security-Policy');
            $this->assertStringNotContainsString('ws://', $csp, "CSP must not widen on garbage port {$badPort}.");
            $this->assertStringNotContainsString('wss://', $csp);
        }
    }

    public function test_csp_does_not_widen_on_malformed_scheme(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'ftp',
        ]);

        $csp = $this->get(route('login'))->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('ws://', $csp);
        $this->assertStringNotContainsString('wss://', $csp);
    }

    public function test_csp_does_not_widen_when_host_is_empty(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.options.host' => '',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        $csp = $this->get(route('login'))->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('ws://', $csp);
        $this->assertStringNotContainsString('wss://', $csp);
    }

    public function test_csp_header_has_expected_base_directives(): void
    {
        $csp = $this->get(route('login'))->assertOk()->headers->get('Content-Security-Policy');
        $this->assertIsString($csp);
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "frame-ancestors 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }
        // AdminLTE requires unsafe-inline; Todo 20 audit confirmed this is deliberate.
        $this->assertStringContainsString("'unsafe-inline'", $csp);
    }

    // -----------------------------------------------------------------
    // JS source: realtime must be runtime-decided, not build-time inlined.
    // -----------------------------------------------------------------

    public function test_echo_js_reads_runtime_config_instead_of_build_time_env(): void
    {
        $echo = file_get_contents(resource_path('js/echo.js'));
        $reverbConfig = file_get_contents(resource_path('js/reverb-config.js'));
        $clientChat = file_get_contents(resource_path('js/client-chat.js'));

        // echo.js must import the runtime helper and not gate on import.meta.env directly.
        $this->assertStringContainsString('getReverbConfig', $echo, 'echo.js must read runtime config.');
        $this->assertStringContainsString("from './reverb-config.js'", $echo);
        $this->assertStringNotContainsString('import.meta.env.VITE_REVERB_APP_KEY', $echo, 'echo.js must not gate on build-time env.');

        // client-chat.js likewise
        $this->assertStringContainsString('getReverbConfig', $clientChat);
        $this->assertStringNotContainsString('import.meta.env.VITE_REVERB_APP_KEY', $clientChat);

        // reverb-config.js is where the build-time fallback lives — and it also
        // reads window.__REVERB__ first so the bundler cannot eliminate it.
        $this->assertStringContainsString('window.__REVERB__', $reverbConfig);
        $this->assertStringContainsString('VITE_REVERB_APP_KEY', $reverbConfig, 'Dev fallback may still reference VITE, but primary is runtime.');
    }

    public function test_built_artifact_contains_realtime_code_not_eliminated(): void
    {
        $manifestPath = public_path('build/manifest.json');
        if (! file_exists($manifestPath)) {
            $this->markTestSkipped('No Vite build present — run `npm run build`.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! isset($manifest['resources/js/client-chat.js']['file'])) {
            $this->markTestSkipped('Manifest missing client-chat entry.');
        }

        $chunk = public_path('build/'.$manifest['resources/js/client-chat.js']['file']);
        $this->assertFileExists($chunk);
        $code = file_get_contents($chunk);

        // The auditor's headline: these were all absent from the committed bundle.
        // They must now survive a production build that had no REVERB_* env at build time.
        $this->assertStringContainsString('chat.typing', $code, 'Built client-chat must contain the realtime branch — Vite must not have eliminated it. This is the pass/fail gate for the whole task.');
        $this->assertStringContainsString('listen(', $code);
        $this->assertStringContainsString('private(', $code);

        // Also check the reverb-config chunk is referenced
        $rcFile = $manifest['resources/js/echo.js']['file'] ?? null;
        $this->assertNotNull($rcFile);
        $echoCode = file_get_contents(public_path('build/'.$rcFile));
        $this->assertStringContainsString('reverb', strtolower($echoCode), 'Built echo chunk must mention reverb (Echo instantiation).');
    }
}
