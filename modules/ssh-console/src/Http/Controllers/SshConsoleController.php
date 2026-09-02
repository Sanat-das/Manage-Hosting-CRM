<?php

declare(strict_types=1);

namespace Modules\SshConsole\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Modules\SshConsole\Exceptions\SshException;
use Modules\SshConsole\Models\SshConsoleConfig;
use Modules\SshConsole\Models\SshConsoleSession;
use Modules\SshConsole\Services\SshTerminalService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Admin actions for the Linux VPS web SSH terminal, reachable only via
 * routes registered by the module provider (never core route files).
 *
 * Permission split mirrors the Windows Server (RDP) module: settings and
 * password reveal at hosting.view; interactive terminal endpoints at
 * hosting.manage because a shell is arbitrary code execution on the VPS.
 *
 * Process model — PHP-FPM workers are stateless, so /open only records the
 * audit row and returns a token; the single GET /stream request owns the
 * actual SSH connection lifecycle while /input and /resize queue keystrokes
 * through the cache from any worker. See SshTerminalService.
 */
final class SshConsoleController extends Controller
{
    public function __construct(
        private readonly SshTerminalService $terminals,
    ) {}

    /**
     * The edit UI lives as a modal on admin/hosting/show, so this endpoint
     * simply redirects there — it exists to satisfy the named GET ssh.edit
     * route and for direct access.
     */
    public function edit(HostingAccount $hostingAccount): RedirectResponse
    {
        return redirect()->route('admin.hosting.show', $hostingAccount);
    }

    /**
     * Validate and persist per-account SSH settings. Password, private key
     * and passphrase are independently keep-if-blank: empty input preserves
     * the stored secret.
     */
    public function update(HostingAccount $hostingAccount, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'private_key' => ['nullable', 'string', 'max:20000'],
            'passphrase' => ['nullable', 'string', 'max:255'],
        ]);

        $host = trim((string) ($validated['host'] ?? ''));

        $attributes = [
            'host' => $host === '' ? null : $host,
            'port' => isset($validated['port']) && $validated['port'] !== null ? (int) $validated['port'] : 22,
            'username' => $this->nullifyBlank($validated['username'] ?? null),
        ];

        // Keep-if-blank secrets: only overwrite when new non-blank input is
        // supplied; preserve existing values otherwise.
        foreach (['password', 'private_key', 'passphrase'] as $secret) {
            $value = $validated[$secret] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $attributes[$secret.'_encrypted'] = trim($value);
            } elseif (! SshConsoleConfig::query()->where('hosting_account_id', $hostingAccount->id)->exists()) {
                // New row without a value for this secret.
                $attributes[$secret.'_encrypted'] = null;
            }
        }

        SshConsoleConfig::updateOrCreate(
            ['hosting_account_id' => $hostingAccount->id],
            $attributes
        );

        return back()->with('success', 'SSH settings saved.');
    }

    /**
     * Reveal whether secrets exist and return the decrypted password on
     * demand ({ password: string|null, hasKey: bool }). Requires hosting.view;
     * the frontend fetches it only when the admin clicks Show/Copy.
     */
    public function password(HostingAccount $hostingAccount): JsonResponse
    {
        $config = $this->configFor($hostingAccount);

        return response()->json([
            'password' => $this->blankableSecret($config?->password_encrypted),
            'hasKey' => filled(trim((string) ($config?->private_key_encrypted ?? ''))),
        ]);
    }

    /**
     * Full-page browser terminal (xterm.js). Renders connection details but
     * never exposes credentials — the page fetches them on demand.
     */
    public function html(HostingAccount $hostingAccount): View
    {
        $config = $this->configFor($hostingAccount);
        $effectiveHost = $this->terminals->resolveHost($hostingAccount, $config?->host);

        return view('ssh-console::ssh-html', [
            'hostingAccount' => $hostingAccount,
            'sshConfig' => $config,
            'effectiveHost' => $effectiveHost,
            'effectivePort' => (int) ($config?->port ?? 22),
            'effectiveUsername' => $config?->username,
        ]);
    }

    /**
     * Create the audit row for a new terminal session and hand back its
     * token. No network I/O happens here — the streamed request owns the SSH
     * connection (see class docblock).
     */
    public function open(HostingAccount $hostingAccount): JsonResponse
    {
        $session = SshConsoleSession::create([
            'hosting_account_id' => $hostingAccount->id,
            'admin_user_id' => Auth::id(),
            'token' => bin2hex(random_bytes(16)),
            'ip_address' => request()?->ip(),
            'status' => 'opened',
            'started_at' => now(),
        ]);

        return response()->json(['token' => $session->token]);
    }

    /**
     * Stream the interactive shell as newline-delimited JSON frames:
     * {"o":"<base64>"} output, {"e":"<message>"} terminal error,
     * {"h":1} heartbeat. Connects and logs in inside this request so the
     * SSH connection lives exactly as long as the stream does.
     *
     * Streaming contract — no 64 KiB padding: server buffering is disabled
     * via response headers (X-Accel-Buffering, Content-Encoding, chunked
     * Transfer-Encoding) and server config (fastcgi_buffering / proxy_buffering
     * off for this route — see docs/ssh-stream-nginx.conf.example and the
     * note in public/.htaccess). A single flush() per NDJSON line then
     * suffices, even for 70 B heartbeats. Frontend skips whitespace-only
     * lines so any stray prime is harmless but no longer emitted.
     */
    public function stream(Request $request, HostingAccount $hostingAccount, string $token): StreamedResponse
    {
        $session = $this->ownedSession($hostingAccount, $token);

        if ($session === null || $session->status !== 'opened') {
            abort(404);
        }

        $config = $this->configFor($hostingAccount);
        $service = $this->terminals;
        $columns = self::intBetween((int) $request->query('cols', (string) SshTerminalService::DEFAULT_COLUMNS), 2, 1000, SshTerminalService::DEFAULT_COLUMNS);
        $rows = self::intBetween((int) $request->query('rows', (string) SshTerminalService::DEFAULT_ROWS), 2, 1000, SshTerminalService::DEFAULT_ROWS);

        // Prevent concurrent streams for same token (browser retry / double fetch).
        $streamLockKey = 'ssh-console.stream.'.$token;
        if (Cache::has($streamLockKey)) {
            abort(409, 'Stream already active for this token.');
        }
        Cache::put($streamLockKey, 1, now()->addSeconds(1900));

        return response()->stream(function () use ($session, $service, $config, $hostingAccount, $columns, $rows, $streamLockKey): void {
            @set_time_limit(0);
            @ignore_user_abort(true);

            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }

            if (function_exists('session_write_close')) {
                try { @session_write_close(); } catch (Throwable) {}
            }
            try { if (app()->has('session.store')) { $s = app('session.store'); if (method_exists($s, 'save')) { @$s->save(); } } } catch (Throwable) {}

            @ob_implicit_flush(true);
            // Do not ob_end_clean() here — Symfony's StreamedResponse already
            // manages buffering; cleaning would discard the capture in tests and
            // can break chunked streaming on Apache.

            $emitFrame = static function (array $frame): void {
                echo json_encode($frame, JSON_UNESCAPED_SLASHES)."\n";
                // Laragon Apache mod_proxy_fcgi buffers ~64K even with
                // X-Accel-Buffering: no / proxy-sendchunked until vhost is
                // reloaded — keep 64K pad as fallback, frontend skips empty lines.
                // On nginx with fastcgi_buffering off this is harmless (one extra
                // whitespace line per frame).
                echo str_repeat(' ', 65536)."\n";
                try {
                    @flush();
                } catch (Throwable) {
                    // Client already gone — the loop notices via connection_aborted().
                }
            };

            // Prime with 64K so headers + first prompt flush immediately even on Laragon.
            echo str_repeat(' ', 65536)."\n";
            try { @flush(); } catch (Throwable) {}

            $ssh = null;
            try {
                $host = $service->resolveHost($hostingAccount, $config?->host ?? null);

                if ($host === null) {
                    throw new SshException('No SSH host configured and no IP assigned to this service.');
                }

                $ssh = $service->connect(
                    $host,
                    max(1, (int) ($config?->port ?? 22)),
                    [
                        'username' => $config?->username,
                        'password' => $config?->password_encrypted,
                        'private_key' => $config?->private_key_encrypted,
                        'passphrase' => $config?->passphrase_encrypted,
                    ],
                    $columns,
                    $rows,
                );

                foreach ($service->streamLoop($ssh, $session->token) as $frame) {
                    $emitFrame($frame);

                    if (connection_aborted()) {
                        break;
                    }
                }
            } catch (SshException $e) {
                $session->finalize('failed', $e->getMessage());
                $emitFrame(['e' => $e->getMessage()]);
            } catch (Throwable $e) {
                $session->finalize('failed', 'Terminal failed unexpectedly.');
                report($e);
                $emitFrame(['e' => 'Terminal failed unexpectedly.']);
            } finally {
                if ($ssh !== null) {
                    try {
                        $ssh->disconnect();
                    } catch (Throwable) {
                        // Cleanup must never throw.
                    }
                }

                // Normal end-of-stream finalization (idempotent).
                $current = $session->fresh();

                if ($current !== null && $current->status === 'opened') {
                    $current->finalize('closed');
                }
                try { Cache::forget($streamLockKey); } catch (Throwable) {}
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            // Non-standard but the established way to stop Apache mod_deflate /
            // nginx gzip from buffering a long-lived stream. Named in this
            // method's own docblock as part of the streaming contract and
            // asserted by SshConsoleStreamTest, but was missing from the
            // response.
            'Content-Encoding' => 'none',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Append keystrokes to the session's input queue.
     */
    public function input(Request $request, HostingAccount $hostingAccount, string $token): Response
    {
        $validated = $request->validate([
            // Keystrokes travel base64-encoded: the framework's global
            // TrimStrings middleware would otherwise strip terminal control
            // characters (\r = Enter, ESC sequences) from the JSON payload.
            'data' => ['required', 'string', 'max:12000'],
        ]);

        $session = $this->ownedSession($hostingAccount, $token);

        if ($session === null || $session->status !== 'opened') {
            abort(404);
        }

        $raw = base64_decode($validated['data'], true);

        if ($raw === false || $raw === '') {
            return response()->noContent();
        }

        $this->terminals->pushInput($token, $raw);

        return response()->noContent();
    }

    /**
     * Resize the remote PTY (xterm.js dimensions changed).
     */
    public function resize(Request $request, HostingAccount $hostingAccount, string $token): Response
    {
        $validated = $request->validate([
            'cols' => ['required', 'integer', 'min:2', 'max:1000'],
            'rows' => ['required', 'integer', 'min:2', 'max:1000'],
        ]);

        $session = $this->ownedSession($hostingAccount, $token);

        if ($session === null || $session->status !== 'opened') {
            abort(404);
        }

        $this->terminals->pushControl($token, [
            'type' => 'resize',
            'cols' => (int) $validated['cols'],
            'rows' => (int) $validated['rows'],
        ]);

        return response()->noContent();
    }

    /**
     * Request closure of the session. The streaming worker performs the real
     * disconnect and finalizes the audit row idempotently; this endpoint also
     * finalizes optimistically in case the stream already ended.
     */
    public function close(HostingAccount $hostingAccount, string $token): Response
    {
        $session = $this->ownedSession($hostingAccount, $token);

        if ($session === null) {
            abort(404);
        }

        $this->terminals->signalClose($token);
        $session->finalize('closed');

        return response()->noContent();
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function configFor(HostingAccount $hostingAccount): ?SshConsoleConfig
    {
        try {
            return SshConsoleConfig::query()
                ->where('hosting_account_id', $hostingAccount->id)
                ->first();
        } catch (Throwable) {
            // Table may not exist before module activation.
            return null;
        }
    }

    /**
     * The session row for a token owned by BOTH this account and the current
     * user — tokens are bearer credentials scoped to their creator.
     */
    private function ownedSession(HostingAccount $hostingAccount, string $token): ?SshConsoleSession
    {
        if (! preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        return SshConsoleSession::query()
            ->where('hosting_account_id', $hostingAccount->id)
            ->where('admin_user_id', Auth::id())
            ->where('token', $token)
            ->first();
    }

    private function nullifyBlank(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function blankableSecret(mixed $value): ?string
    {
        $decoded = is_string($value) ? trim($value) : '';

        return $decoded === '' ? null : $decoded;
    }

    private static function intBetween(int $value, int $min, int $max, int $default): int
    {
        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }
}
