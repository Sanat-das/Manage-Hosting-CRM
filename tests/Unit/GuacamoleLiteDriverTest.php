<?php

declare(strict_types=1);

namespace Tests\Unit;

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
}
