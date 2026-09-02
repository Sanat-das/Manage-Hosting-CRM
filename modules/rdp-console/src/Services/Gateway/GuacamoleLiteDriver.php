<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Services\Gateway;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Mints connection tokens understood by the Node guacamole-lite sidecar
 * (todo 10) which relays them to a local guacd daemon.
 *
 * Wire format is exactly what guacamole-lite's lib/Crypt.js decrypts:
 * base64(JSON{iv,value}) where iv/value are base64 of a random 16-byte IV
 * and the AES-256-CBC ciphertext. The key derivation intentionally matches
 * Crypt.js: the RAW secret is truncated with NUL-padding to exactly 32 bytes
 * (Buffer.from(secret).slice(0, 32) padded) — it is NOT hashed, so the PHP
 * side must never switch to hash('sha256', ...) or Node decryption breaks.
 *
 * The token embeds its expiry as `exp` (unix seconds); the sidecar rejects
 * expired tokens in processConnectionSettings. Single-use enforcement is NOT
 * possible on the PHP side and remains a documented limitation.
 */
final class GuacamoleLiteDriver implements GatewayDriver
{
    /** Connection-token lifetime in seconds: just enough to open one console. */
    private const TOKEN_TTL = 90;

    /** AES-256 key size; also the slice/pad length applied to the raw secret. */
    private const KEY_SIZE = 32;

    private const IV_SIZE = 16;

    public function __construct(
        private readonly ?string $secret = null,
        private readonly ?string $wsUrl = null,
        private readonly ?string $recordingPath = null,
    ) {}

    public function wsUrl(): string
    {
        return rtrim((string) ($this->wsUrl ?? config('rdp-console.ws_url', 'ws://127.0.0.1:8080/')), '/').'/';
    }

    public function mint(RdpConnectionContext $context): string
    {
        $key = $this->derivedKey();
        $expiresAt = $context->expiresAt ?? time() + self::TOKEN_TTL;

        $settings = [
            'hostname' => $context->hostname,
            'port' => $context->port,
            'username' => $context->username,
            'password' => $context->password,
            'domain' => $context->domain === '' ? null : $context->domain,
            'security' => $context->security,
            'resize-method' => 'display-update',
            'enable-drive' => true,
            'drive-path' => 'C:\\guac-transfer',
        ];

        // Recording parameters are optional to guacd; emitting an empty path
        // would abort session startup, so only send them when configured.
        $recordingPath = trim($this->recordingPath ?? (string) config('rdp-console.recording_path'));

        if ($recordingPath !== '') {
            $settings['create-recording-path'] = true;
            $settings['recording-path'] = $recordingPath;
            $settings['recording-name'] = sprintf(
                'rdp-%s-%s',
                date('Ymd_His'),
                bin2hex(random_bytes(3)),
            );
        }

        $token = $this->encrypt([
            'connection' => [
                'type' => 'rdp',
                'settings' => [...$settings, 'exp' => $expiresAt],
            ],
        ], $key);

        // Audit trail without credential material — never ModuleLog.
        Log::info('rdp.token.minted', [
            'admin' => $context->adminUserId,
            'account' => $context->accountId,
        ]);

        return $token;
    }

    /**
     * Test-only counterpart to mint(): decrypts a token with the SAME raw
     * pad/truncate derivation so tests can prove round-trip fidelity without
     * standing up the Node sidecar. Never use in request paths.
     *
     * @return array<string, mixed>
     */
    public function decryptForTest(string $token): array
    {
        $raw = base64_decode($token, true);

        if ($raw === false) {
            throw new RuntimeException('Gateway token is not valid base64.');
        }

        try {
            /** @var mixed $envelope */
            $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Gateway token envelope is malformed.', 0, $e);
        }

        if (! is_array($envelope) || ! isset($envelope['iv'], $envelope['value'])) {
            throw new RuntimeException('Gateway token envelope is malformed.');
        }

        $iv = base64_decode((string) $envelope['iv'], true);
        $ciphertext = base64_decode((string) $envelope['value'], true);

        if ($iv === false || $ciphertext === false || strlen($iv) !== self::IV_SIZE) {
            throw new RuntimeException('Gateway token payload is malformed.');
        }

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $this->derivedKey(), OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw new RuntimeException('Gateway token failed to decrypt (wrong secret or tampered token).');
        }

        try {
            /** @var mixed $settings */
            $settings = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Gateway token payload is not valid JSON.', 0, $e);
        }

        if (! is_array($settings)) {
            throw new RuntimeException('Gateway token payload is not valid JSON.');
        }

        return $settings;
    }

    /**
     * The shared secret validated then truncated/NUL-padded to 32 bytes,
     * mirroring guacamole-lite's Buffer.from(secret).slice(0, 32) handling.
     */
    private function derivedKey(): string
    {
        $secret = trim((string) ($this->secret ?? config('rdp-console.secret')));

        if ($secret === '') {
            throw new RuntimeException('GUACAMOLE_SECRET is not configured.');
        }

        if (strlen($secret) < 16) {
            throw new RuntimeException('GUACAMOLE_SECRET must be at least 16 characters.');
        }

        return str_pad(substr($secret, 0, self::KEY_SIZE), self::KEY_SIZE, "\0");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encrypt(array $payload, string $key): string
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Unable to serialize the RDP gateway payload.', 0, $e);
        }

        $iv = random_bytes(self::IV_SIZE);

        $ciphertext = openssl_encrypt($json, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt the RDP gateway token.');
        }

        try {
            return base64_encode((string) json_encode([
                'iv' => base64_encode($iv),
                'value' => base64_encode($ciphertext),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            throw new RuntimeException('Unable to encode the RDP gateway token.', 0, $e);
        }
    }
}
