<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\RdpConsole\Exceptions\GatewayNotConfiguredException;
use Modules\RdpConsole\Services\Gateway\GuacamoleLiteDriver;
use Modules\RdpConsole\Services\Gateway\RdpConnectionContext;
use RuntimeException;
use Tests\TestCase;

/**
 * Plan task 9 — guacamole-lite token minting.
 *
 * The driver must produce AES-256-CBC tokens shaped exactly like
 * guacamole-lite's Crypt.js expects: base64(JSON{iv,value}), both values
 * base64, key derived by truncating/padding the RAW secret to 32 bytes
 * (NOT a SHA-256 hash). Tokens embed a 90s TTL as `exp` and never leak
 * plaintext secrets or credentials.
 */
final class GuacamoleLiteDriverTest extends TestCase
{
    private const SECRET = 'unit-test-secret-0123456789abcdef';

    public function test_mint_round_trips_to_identical_connection_settings(): void
    {
        $driver = new GuacamoleLiteDriver(
            secret: self::SECRET,
            wsUrl: 'ws://sidecar.test:9000/',
            recordingPath: 'C:\\rdp-recordings',
        );

        $before = time();
        $token = $driver->mint(new RdpConnectionContext(
            hostname: 'rdp.example.internal',
            port: 3390,
            username: 'Administrator',
            password: 'S3cret-P@ss',
            domain: '',
            adminUserId: 7,
            accountId: 42,
        ));
        $after = time();

        // Envelope shape: base64(JSON{iv,value}) with no plaintext material.
        $raw = base64_decode($token, true);
        $this->assertNotFalse($raw, 'Token must be valid base64.');

        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['iv', 'value'], array_keys($envelope));
        $this->assertStringNotContainsString(self::SECRET, $token);
        $this->assertStringNotContainsString('S3cret-P@ss', $token);
        $this->assertStringNotContainsString('rdp.example.internal', $token);

        $settings = $driver->decryptForTest($token);

        $this->assertSame('rdp', $settings['connection']['type']);
        $connection = $settings['connection']['settings'];
        $this->assertSame('rdp.example.internal', $connection['hostname']);
        $this->assertSame(3390, $connection['port']);
        $this->assertSame('Administrator', $connection['username']);
        $this->assertSame('S3cret-P@ss', $connection['password']);
        $this->assertNull($connection['domain'], 'A blank domain must mint as null.');
        $this->assertSame('nla', $connection['security']);
        $this->assertSame('display-update', $connection['resize-method']);
        $this->assertTrue($connection['enable-drive']);
        $this->assertSame('C:\\guac-transfer', $connection['drive-path']);
        $this->assertTrue($connection['create-recording-path']);
        $this->assertSame('C:\\rdp-recordings', $connection['recording-path']);
        $this->assertMatchesRegularExpression(
            '/^rdp-\d{8}_\d{6}-[0-9a-f]{6}$/',
            (string) $connection['recording-name'],
            'Recording name must be a plain timestamped file name.',
        );
        $this->assertIsInt($connection['exp']);
        $this->assertGreaterThanOrEqual($before + 89, $connection['exp']);
        $this->assertLessThanOrEqual($after + 90, $connection['exp']);
    }

    public function test_non_blank_domain_is_passed_through(): void
    {
        $driver = new GuacamoleLiteDriver(secret: self::SECRET);

        $settings = $driver->decryptForTest($driver->mint(new RdpConnectionContext(
            hostname: 'host1',
            port: 3389,
            username: 'user',
            password: 'pass',
            domain: 'CORP',
        )));

        $this->assertSame('CORP', $settings['connection']['settings']['domain']);
    }

    // ==================================================================
    // Mode separation: guest-RDP wire shape is frozen; VMConnect is a
    // different settings array with a connection-scoped ignore-cert.
    // ==================================================================

    /**
     * The guest-RDP settings array is a frozen wire contract — the VMConnect
     * work must not have added, dropped or reordered a key, and must never
     * leak `ignore-cert` (a global certificate bypass would expose guest RDP
     * sessions to MITM).
     */
    public function test_guest_rdp_settings_shape_is_frozen(): void
    {
        $driver = new GuacamoleLiteDriver(secret: self::SECRET);

        $connection = $driver->decryptForTest($driver->mint(new RdpConnectionContext(
            hostname: 'rdp.example.internal',
            port: 3390,
            username: 'Administrator',
            password: 'S3cret-P@ss',
            domain: 'CORP',
            security: 'nla',
        )))['connection']['settings'];

        $this->assertSame([
            'hostname',
            'port',
            'username',
            'password',
            'domain',
            'security',
            'resize-method',
            'enable-drive',
            'drive-path',
            'exp',
        ], array_keys($connection), 'Guest-RDP settings must stay byte-for-byte the same contract.');

        $this->assertSame('nla', $connection['security']);
        $this->assertArrayNotHasKey('ignore-cert', $connection, 'ignore-cert is VMConnect-only and must never appear in a guest-RDP token.');
        $this->assertArrayNotHasKey('preconnection-blob', $connection);
    }

    public function test_vmconnect_mint_emits_hyperv_settings(): void
    {
        $driver = new GuacamoleLiteDriver(secret: self::SECRET);

        $before = time();
        $token = $driver->mint(RdpConnectionContext::hyperV(
            hostname: '10.1.3.133',
            vmGuid: 'be7b0864-7b3f-4429-9a20-8df961ef172f',
            username: 'HOST-ADMIN',
            password: 'HOST-ADMIN-PW',
        ));
        $after = time();

        $connection = $driver->decryptForTest($token)['connection']['settings'];

        $this->assertSame([
            'hostname',
            'port',
            'username',
            'password',
            'security',
            'preconnection-blob',
            'ignore-cert',
            'exp',
        ], array_keys($connection), 'VMConnect settings must be exactly these keys, in this order.');

        $this->assertSame('10.1.3.133', $connection['hostname']);
        $this->assertSame(2179, $connection['port'], 'VMConnect must target the Hyper-V vmrdp port.');
        $this->assertSame('HOST-ADMIN', $connection['username'], 'VMConnect authenticates as the HOST administrator.');
        $this->assertSame('HOST-ADMIN-PW', $connection['password']);
        $this->assertSame('vmconnect', $connection['security']);
        $this->assertSame('be7b0864-7b3f-4429-9a20-8df961ef172f', $connection['preconnection-blob']);
        $this->assertTrue($connection['ignore-cert'], 'Hyper-V may present a self-signed certificate; ignore-cert must be scoped to this connection.');
        $this->assertArrayNotHasKey('preconnection-id', $connection, 'The manual says to leave preconnection-id blank for Hyper-V.');
        $this->assertArrayNotHasKey('domain', $connection);
        $this->assertArrayNotHasKey('enable-drive', $connection, 'Drive redirection is not meaningful in a Hyper-V basic session.');
        $this->assertArrayNotHasKey('drive-path', $connection);
        $this->assertArrayNotHasKey('resize-method', $connection, 'Dynamic resize needs guest support the basic session may not honour.');
        $this->assertIsInt($connection['exp']);
        $this->assertGreaterThanOrEqual($before + 89, $connection['exp']);
        $this->assertLessThanOrEqual($after + 90, $connection['exp']);
    }

    public function test_vmconnect_recording_settings_match_the_guest_mode(): void
    {
        $driver = new GuacamoleLiteDriver(secret: self::SECRET, recordingPath: 'C:\\rdp-recordings');

        $connection = $driver->decryptForTest($driver->mint(RdpConnectionContext::hyperV(
            hostname: '10.1.3.133',
            vmGuid: 'be7b0864-7b3f-4429-9a20-8df961ef172f',
            username: 'HOST-ADMIN',
            password: 'HOST-ADMIN-PW',
        )))['connection']['settings'];

        $this->assertTrue($connection['create-recording-path']);
        $this->assertSame('C:\\rdp-recordings', $connection['recording-path']);
        $this->assertMatchesRegularExpression(
            '/^rdp-\d{8}_\d{6}-[0-9a-f]{6}$/',
            (string) $connection['recording-name'],
        );
    }

    public function test_vmconnect_requires_a_vm_guid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RdpConnectionContext::hyperV(
            hostname: '10.1.3.133',
            vmGuid: '   ',
            username: 'HOST-ADMIN',
            password: 'HOST-ADMIN-PW',
        );
    }

    public function test_two_tokens_never_share_ciphertext_thanks_to_random_iv(): void
    {
        $driver = new GuacamoleLiteDriver(secret: self::SECRET);
        $context = new RdpConnectionContext(hostname: 'h', port: 3389, username: 'u', password: 'p');

        $first = $driver->mint($context);
        $second = $driver->mint($context);

        $this->assertNotSame($first, $second);
        $this->assertNotSame(
            json_decode((string) base64_decode($first, true), true)['value'],
            json_decode((string) base64_decode($second, true), true)['value'],
        );
    }

    public function test_ws_url_and_config_fallback_are_read_from_module_config(): void
    {
        config()->set('rdp-console.ws_url', 'wss://gateway.example/ws/');
        config()->set('rdp-console.secret', self::SECRET);

        $driver = new GuacamoleLiteDriver;

        $this->assertSame('wss://gateway.example/ws/', $driver->wsUrl());
        // Minting through the config-provided secret proves the fallback path.
        $settings = $driver->decryptForTest($driver->mint(new RdpConnectionContext(
            hostname: 'h',
            port: 3389,
            username: 'u',
            password: 'p',
        )));
        $this->assertSame('h', $settings['connection']['settings']['hostname']);
    }

    /**
     * Regression: every other test in this file constructs the driver with an
     * explicit secret, which is exactly why a missing/renamed module config
     * file went unnoticed. The real container binding (RdpConsole::boot)
     * passes NO constructor arguments, so the fallback keys
     * config('rdp-console.secret'|'ws_url'|'recording_path') must be declared
     * by an actually loaded config/rdp-console.php — not merely settable at
     * runtime by config()->set().
     */
    public function test_module_config_file_declares_the_gateway_keys(): void
    {
        $config = config('rdp-console');

        $this->assertIsArray($config, 'config/rdp-console.php must exist and be loaded.');
        $this->assertArrayHasKey('secret', $config);
        $this->assertArrayHasKey('ws_url', $config);
        $this->assertArrayHasKey('recording_path', $config);
    }

    public function test_tampered_token_is_rejected(): void
    {
        $driver = new GuacamoleLiteDriver(secret: self::SECRET);
        $token = $driver->mint(new RdpConnectionContext(hostname: 'h', port: 3389, username: 'u', password: 'p'));

        $raw = base64_decode($token, true);
        $this->assertNotFalse($raw);
        $offset = strlen($raw) - 5; // Flip a byte inside the ciphertext block.
        $raw[$offset] = chr(ord($raw[$offset]) ^ 0xFF);

        $this->expectException(RuntimeException::class);
        $driver->decryptForTest(base64_encode($raw));
    }

    public function test_missing_secret_is_rejected(): void
    {
        config()->set('rdp-console.secret', null);
        $driver = new GuacamoleLiteDriver;

        $this->expectException(RuntimeException::class);
        $driver->mint(new RdpConnectionContext(hostname: 'h', port: 3389, username: 'u', password: 'p'));
    }

    public function test_short_secret_is_rejected(): void
    {
        $driver = new GuacamoleLiteDriver(secret: 'tooshort');

        $this->expectException(RuntimeException::class);
        $driver->mint(new RdpConnectionContext(hostname: 'h', port: 3389, username: 'u', password: 'p'));
    }

    // ==================================================================
    // "Not configured" is a distinct, operator-fixable state — never a
    // silent default, and surfaced gracefully by the console endpoints.
    // ==================================================================

    public function test_is_configured_reflects_secret_validity(): void
    {
        config()->set('rdp-console.secret', null);
        $this->assertFalse((new GuacamoleLiteDriver)->isConfigured(), 'No secret must read as not configured.');

        $this->assertFalse((new GuacamoleLiteDriver(secret: '   '))->isConfigured(), 'A blank secret must read as not configured.');
        $this->assertFalse((new GuacamoleLiteDriver(secret: 'tooshort'))->isConfigured(), 'A short secret must read as not configured.');

        $this->assertTrue((new GuacamoleLiteDriver(secret: self::SECRET))->isConfigured());
    }

    /**
     * The distinct type is what lets the endpoints answer 503 "not
     * configured" instead of an unhandled 500 — and it is still a
     * RuntimeException, so a missing secret keeps failing closed.
     */
    public function test_missing_secret_throws_the_not_configured_exception(): void
    {
        config()->set('rdp-console.secret', null);

        $this->expectException(GatewayNotConfiguredException::class);
        (new GuacamoleLiteDriver)->mint(new RdpConnectionContext(hostname: 'h', port: 3389, username: 'u', password: 'p'));
    }
}
