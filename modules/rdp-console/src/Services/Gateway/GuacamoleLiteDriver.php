<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Services\Gateway;

use Illuminate\Support\Facades\Log;
use Modules\RdpConsole\Exceptions\GatewayNotConfiguredException;
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

    /**
     * Whether a usable shared secret is present. The console pages call this
     * to render an honest "gateway not configured" state instead of offering
     * a Connect button that cannot mint. It deliberately does NOT relax
     * mint(): a missing secret still throws and every endpoint still fails
     * closed — no default, fallback or generated secret exists.
     */
    public function isConfigured(): bool
    {
        try {
            $this->derivedKey();

            return true;
        } catch (GatewayNotConfiguredException) {
            return false;
        }
    }

    public function mint(RdpConnectionContext $context): string
    {
        $key = $this->derivedKey();
        $expiresAt = $context->expiresAt ?? time() + self::TOKEN_TTL;

        $settings = match ($context->mode) {
            RdpConnectionMode::GuestRdp => $this->guestRdpSettings($context),
            RdpConnectionMode::HyperVVmConnect => $this->vmConnectSettings($context),
        };

        // Recording parameters are optional to guacd; emitting an empty path
        // would abort session startup, so only send them when configured.
        // Both modes behave identically here — a configured recording path
        // records the session regardless of which RDP server answered.
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

        // Audit trail without credential material — never ModuleLog. The mode
        // is a routing fact, not a secret; it is what makes a VMConnect mint
        // distinguishable from a guest-RDP mint after the fact.
        Log::info('rdp.token.minted', [
            'admin' => $context->adminUserId,
            'account' => $context->accountId,
            'mode' => $context->mode->value,
        ]);

        return $token;
    }

    /**
     * Guest-RDP settings. This array is a frozen wire contract: existing
     * tokens are asserted against it, so do not add, drop or reorder keys —
     * VMConnect differences live in vmConnectSettings() instead.
     *
     * @return array<string, mixed>
     */
    private function guestRdpSettings(RdpConnectionContext $context): array
    {
        return [
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
    }

    /**
     * Hyper-V VMConnect settings. Targets the Hyper-V host's vmrdp listener
     * with the HOST's administrator credentials and the VM GUID as the
     * preconnection BLOB, so the console works without guest networking and
     * at boot / pre-OS screens.
     *
     * Deliberately different from guest-RDP beyond security/port:
     *
     * - `ignore-cert` is scoped to THIS connection's settings only. Hyper-V may
     *   present a self-signed certificate, so this session has to tolerate it;
     *   a global guacd default would silently expose every guest-RDP session
     *   to MITM, which is why the driver never sets one.
     * - `preconnection-id` is omitted: the Guacamole manual says to leave it
     *   blank for Hyper-V (guacd treats blank as "no ID").
     * - `domain` is omitted: the Server row carries no AD domain for the host
     *   administrator login, and sending a guest domain would be wrong.
     * - `enable-drive`/`drive-path` are omitted: drive redirection is an
     *   RDPDR feature Hyper-V's basic session does not provide, and the old
     *   `C:\guac-transfer` value is a path on the *guacd* host (a Linux
     *   container in the documented deployment), not on the target.
     * - `resize-method` is omitted: guacd 1.5.5 defaults to "none" when the
     *   argument is blank (settings.c), dynamic resize needs RDPEDISP support
     *   the basic session may not honour, and the browser canvas already
     *   scales the display to the panel client-side.
     *
     * @return array<string, mixed>
     */
    private function vmConnectSettings(RdpConnectionContext $context): array
    {
        return [
            'hostname' => $context->hostname,
            'port' => $context->port,
            'username' => $context->username,
            'password' => $context->password,
            'security' => 'vmconnect',
            'preconnection-blob' => (string) $context->preconnectionBlob,
            'ignore-cert' => true,
        ];
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
     *
     * A missing/short secret throws GatewayNotConfiguredException (a distinct
     * RuntimeException subtype) so the endpoints can answer a graceful 503
     * while still failing closed.
     */
    private function derivedKey(): string
    {
        $secret = trim((string) ($this->secret ?? config('rdp-console.secret')));

        if ($secret === '') {
            throw new GatewayNotConfiguredException('GUACAMOLE_SECRET is not configured.');
        }

        if (strlen($secret) < 16) {
            throw new GatewayNotConfiguredException('GUACAMOLE_SECRET must be at least 16 characters.');
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
