<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\RdpConsole\Exceptions\GatewayNotConfiguredException;
use Modules\RdpConsole\Exceptions\VmConnectUnavailableException;
use Modules\RdpConsole\Models\RdpConsoleConfig;
use Modules\RdpConsole\Services\Gateway\GatewayDriver;
use Modules\RdpConsole\Services\Gateway\RdpConnectionContext;
use Modules\RdpConsole\Services\VmConnect\VmConnectTargetResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin actions for the Windows Server (RDP) module, reachable only via
 * routes registered by the module provider (never core route files).
 */
final class RdpConsoleController extends Controller
{
    public function __construct(
        private readonly VmConnectTargetResolver $vmConnectTargetResolver,
    ) {}
    /**
     * Show RDP configuration for a hosting account. The edit UI lives as a
     * modal on admin/hosting/show, so this endpoint simply redirects there —
     * it exists to satisfy the named GET rdp.edit route and for direct access.
     */
    public function rdpEdit(HostingAccount $hostingAccount): RedirectResponse
    {
        return redirect()->route('admin.hosting.show', $hostingAccount);
    }

    /**
     * Validate and persist per-account RDP settings.
     */
    public function rdpUpdate(HostingAccount $hostingAccount, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
        ]);

        $host = trim((string) ($validated['host'] ?? ''));

        if ($host === '') {
            $host = null;
        }

        $port = isset($validated['port']) && $validated['port'] !== null ? (int) $validated['port'] : 3389;

        $username = trim((string) ($validated['username'] ?? ''));
        $username = $username === '' ? null : $username;

        $domain = trim((string) ($validated['domain'] ?? ''));
        $domain = $domain === '' ? null : $domain;

        $attributes = [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'domain' => $domain,
        ];

        // Only overwrite password when a new value is supplied; empty input keeps existing.
        $password = $validated['password'] ?? null;

        if (is_string($password) && trim($password) !== '') {
            $attributes['password_encrypted'] = trim($password);
        } elseif (RdpConsoleConfig::query()->where('hosting_account_id', $hostingAccount->id)->exists()) {
            // Preserve existing encrypted password — do not null it on empty input.
        } else {
            $attributes['password_encrypted'] = null;
        }

        RdpConsoleConfig::updateOrCreate(
            ['hosting_account_id' => $hostingAccount->id],
            $attributes
        );

        return back()->with('success', 'RDP settings saved.');
    }

    /**
     * HTML version of the RDP connection — renders a browser page with
     * connection details, .rdp preview, and an optional Guacamole/IronRDP
     * iframe console. Never exposes the password.
     */
    public function rdpHtml(HostingAccount $hostingAccount): View
    {
        $rdpConfig = RdpConsoleConfig::query()
            ->where('hosting_account_id', $hostingAccount->id)
            ->first();

        $host = $rdpConfig?->host;
        $port = $rdpConfig?->port ?? 3389;

        if ($host === null || trim($host) === '') {
            $fallback = $hostingAccount->ipAddresses()->where('type', 'public')->first()
                ?? $hostingAccount->ipAddresses()->first();

            $host = $fallback?->ip_address;
        }

        $effectiveHost = $host !== null ? trim((string) $host) : null;
        if ($effectiveHost === '') {
            $effectiveHost = null;
        }
        $effectivePort = (int) $port;
        $fullAddress = $effectiveHost !== null ? $effectiveHost.':'.$effectivePort : null;

        // The canvas can only connect when a host exists AND the gateway can
        // mint. The not-configured state is surfaced through the canvas's own
        // error region (reused, not a new pattern) and disables Connect.
        $gatewayConfigured = app(GatewayDriver::class)->isConfigured();

        $consoleDisabledReason = ! $gatewayConfigured
            ? GatewayNotConfiguredException::OPERATOR_MESSAGE
            : ($effectiveHost === null
                ? 'No RDP host available. Configure an RDP host or assign an IP to this account, then reload.'
                : '');

        return view('rdp-console::rdp-html', [
            'hostingAccount' => $hostingAccount,
            'rdpConfig' => $rdpConfig,
            'effectiveHost' => $effectiveHost,
            'effectivePort' => $effectivePort,
            'fullAddress' => $fullAddress,
            'gatewayConfigured' => $gatewayConfigured,
            'consoleDisabledReason' => $consoleDisabledReason,
        ]);
    }

    /**
     * Mint a short-lived gateway token for the browser HTML5 RDP console.
     * Returns { ws_url, token } where the AES-encrypted token carries the
     * connection settings to the guacamole-lite sidecar — the plaintext
     * password never appears in the URL, the page, or this JSON body.
     * Responds 404 when host/username/password are not fully configured, 503
     * when the gateway has no shared secret, and 500 (generic, logged) when
     * minting fails for any other reason.
     */
    public function rdpToken(HostingAccount $hostingAccount): JsonResponse
    {
        $rdpConfig = RdpConsoleConfig::query()
            ->where('hosting_account_id', $hostingAccount->id)
            ->first();

        $host = $rdpConfig?->host;

        if ($host === null || trim($host) === '') {
            $fallback = $hostingAccount->ipAddresses()->where('type', 'public')->first()
                ?? $hostingAccount->ipAddresses()->first();

            $host = $fallback?->ip_address;
        }

        $host = trim((string) $host);
        $username = trim((string) ($rdpConfig?->username ?? ''));
        $password = trim((string) ($rdpConfig?->password_encrypted ?? ''));

        if ($host === '' || $username === '' || $password === '') {
            return response()->json([
                'error' => 'RDP connection details are incomplete. Configure host, username and password first.',
            ], 404);
        }

        $driver = app(GatewayDriver::class);

        try {
            $token = $driver->mint(new RdpConnectionContext(
                hostname: $host,
                port: (int) ($rdpConfig?->port ?? 3389),
                username: $username,
                password: $password,
                domain: $rdpConfig?->domain,
                adminUserId: (int) auth()->id(),
                accountId: (int) $hostingAccount->id,
            ));
        } catch (GatewayNotConfiguredException $e) {
            return $this->gatewayNotConfiguredResponse($hostingAccount, 'rdp-token', $e);
        } catch (\Throwable $e) {
            return $this->tokenMintFailedResponse($hostingAccount, 'rdp-token', $e);
        }

        return response()->json([
            'ws_url' => $driver->wsUrl(),
            'token' => $token,
        ]);
    }

    /**
     * Hyper-V VMConnect console page. A second, explicit connection mode that
     * talks to the Hyper-V host's vmrdp listener (port 2179) with the HOST's
     * administrator credentials and the VM GUID — not to the guest's RDP
     * service. Works with no guest network and at boot / pre-OS screens.
     *
     * Everything about the target is resolved server-side by the resolver;
     * only non-secret facts (host, port, GUID) and an operator-safe error are
     * handed to the view. Credentials never reach the template.
     */
    public function vmConsole(HostingAccount $hostingAccount): View
    {
        $consoleHost = null;
        $consoleVmGuid = null;
        $consoleError = null;

        try {
            $target = $this->vmConnectTargetResolver->resolve($hostingAccount);
            $consoleHost = $target->hostname;
            $consoleVmGuid = $target->vmGuid;
        } catch (VmConnectUnavailableException $e) {
            $consoleError = $e->getMessage();
        }

        // A missing gateway secret blocks Connect entirely, so it is reported
        // before a target problem: it is the actionable blocker to fix first.
        // The reason reuses the canvas's existing error region and disables
        // the Connect control — no new UI pattern.
        $gatewayConfigured = app(GatewayDriver::class)->isConfigured();

        $consoleDisabledReason = ! $gatewayConfigured
            ? GatewayNotConfiguredException::OPERATOR_MESSAGE
            : ($consoleError ?? 'The VM console is not available right now.');

        return view('rdp-console::vm-console', [
            'hostingAccount' => $hostingAccount,
            'consoleHost' => $consoleHost,
            'consolePort' => RdpConnectionContext::VMCONNECT_PORT,
            'consoleVmGuid' => $consoleVmGuid,
            'consoleError' => $consoleError,
            'consoleEnabled' => $consoleError === null && $gatewayConfigured,
            'consoleDisabledReason' => $consoleDisabledReason,
        ]);
    }

    /**
     * Mint a short-lived gateway token for the Hyper-V VMConnect console.
     * Returns the same { ws_url, token } shape as rdpToken() so the shared
     * Guacamole canvas consumes it unchanged.
     *
     * No part of the target comes from the request: the host, port, VM GUID,
     * security mode and HOST credentials are resolved from the account's
     * PanelAccount and Server rows. Responds 404 — the module's existing
     * "incomplete configuration" contract — when any of them is missing, 503
     * when the gateway has no shared secret, and 500 (generic, logged) when
     * minting fails for any other reason. Never falls back to the guest's
     * credentials or to another VM.
     */
    public function vmConsoleToken(HostingAccount $hostingAccount): JsonResponse
    {
        try {
            $target = $this->vmConnectTargetResolver->resolve($hostingAccount);
        } catch (VmConnectUnavailableException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        $driver = app(GatewayDriver::class);

        try {
            $token = $driver->mint(RdpConnectionContext::hyperV(
                hostname: $target->hostname,
                vmGuid: $target->vmGuid,
                username: $target->username,
                password: $target->password,
                adminUserId: (int) auth()->id(),
                accountId: (int) $hostingAccount->id,
            ));
        } catch (GatewayNotConfiguredException $e) {
            return $this->gatewayNotConfiguredResponse($hostingAccount, 'vm-console-token', $e);
        } catch (\Throwable $e) {
            return $this->tokenMintFailedResponse($hostingAccount, 'vm-console-token', $e);
        }

        return response()->json([
            'ws_url' => $driver->wsUrl(),
            'token' => $token,
        ]);
    }

    /**
     * Failure surface for "the gateway has no shared secret". The real reason
     * is logged server-side; the browser gets the fixed OPERATOR_MESSAGE,
     * which names the setting to fix but carries no exception message, class,
     * stack trace, file path or config value. The mint already failed closed —
     * there is no default or fallback secret.
     */
    private function gatewayNotConfiguredResponse(HostingAccount $hostingAccount, string $endpoint, GatewayNotConfiguredException $e): JsonResponse
    {
        Log::error('rdp.console.gateway_not_configured', [
            'account' => $hostingAccount->id,
            'endpoint' => $endpoint,
            'reason' => $e->getMessage(),
        ]);

        return response()->json(['error' => GatewayNotConfiguredException::OPERATOR_MESSAGE], 503);
    }

    /**
     * Any other mint failure (encryption / serialization). Logged with its
     * real reason; surfaced generically so no internal detail reaches the
     * browser.
     */
    private function tokenMintFailedResponse(HostingAccount $hostingAccount, string $endpoint, \Throwable $e): JsonResponse
    {
        Log::error('rdp.console.token_mint_failed', [
            'account' => $hostingAccount->id,
            'endpoint' => $endpoint,
            'exception' => $e::class,
            'reason' => $e->getMessage(),
        ]);

        return response()->json([
            'error' => 'The console gateway could not issue a token. Check the server logs.',
        ], 500);
    }

    /**
     * Serve the vendored guacamole-common-js browser client from the
     * module's resources directory. Kept behind admin auth like the console
     * itself; the response is cacheable since the vendored file is immutable.
     */
    public function rdpClientAsset(HostingAccount $hostingAccount): BinaryFileResponse
    {
        $path = dirname(__DIR__, 3).'/resources/assets/guacamole-common.min.js';

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Reveal the stored RDP password (decrypted via the model's encrypted cast).
     * Requires hosting.view; the value is never rendered in the initial HTML —
     * the frontend fetches it on demand and the admin can copy it. Returns
     * { password: string|null }.
     */
    public function rdpPassword(HostingAccount $hostingAccount): JsonResponse
    {
        $rdpConfig = RdpConsoleConfig::query()
            ->where('hosting_account_id', $hostingAccount->id)
            ->first();

        $password = $rdpConfig?->password_encrypted;

        if ($password === null || trim((string) $password) === '') {
            return response()->json(['password' => null]);
        }

        return response()->json(['password' => (string) $password]);
    }

    /**
     * Download a .rdp file for the hosting account. When a password is stored
     * it is embedded as `password 51:b:` using Windows DPAPI (ConvertFrom-SecureString).
     * The blob is bound to the Windows user that generated it on the server — on other
     * machines/accounts the client will still prompt, so the UI also offers view/copy.
     */
    public function rdpDownload(HostingAccount $hostingAccount): StreamedResponse|RedirectResponse
    {
        $rdpConfig = RdpConsoleConfig::query()
            ->where('hosting_account_id', $hostingAccount->id)
            ->first();

        $host = $rdpConfig?->host;
        $port = $rdpConfig?->port ?? 3389;
        $username = $rdpConfig?->username;
        $domain = $rdpConfig?->domain;

        if ($host === null || trim($host) === '') {
            $fallback = $hostingAccount->ipAddresses()->where('type', 'public')->first()
                ?? $hostingAccount->ipAddresses()->first();

            $host = $fallback?->ip_address;
        }

        if ($host === null || trim($host) === '') {
            return back()->with('error', 'No RDP host available. Configure an RDP host or assign an IP to this account.');
        }

        $host = trim($host);
        $fullAddress = $host.':'.$port;

        $lines = [
            'full address:s:'.$fullAddress,
        ];

        if ($username !== null && trim($username) !== '') {
            $lines[] = 'username:s:'.trim($username);
        }

        if ($domain !== null && trim($domain) !== '') {
            $lines[] = 'domain:s:'.trim($domain);
        }

        $password = $rdpConfig?->password_encrypted;
        $encryptedPassword = null;
        if (is_string($password) && trim($password) !== '') {
            $encryptedPassword = $this->encryptRdpPassword(trim($password));
            if ($encryptedPassword !== null && $encryptedPassword !== '') {
                $lines[] = 'password 51:b:'.$encryptedPassword;
            }
        }

        $hasEmbeddedPassword = $encryptedPassword !== null && $encryptedPassword !== '';

        $lines = array_merge($lines, [
            'screen mode id:i:2',
            'session bpp:i:32',
            'autoreconnection enabled:i:1',
            'compression:i:1',
            'keyboardhook:i:2',
            'audiomode:i:0',
            'displayconnectionbar:i:1',
            // Keep CredSSP/NLA enabled — disabling it breaks servers that require NLA (0xb09).
            // Auto-logon with an embedded password 51:b works with CredSSP on; we only
            // suppress the extra prompt.
            'prompt for credentials:i:'.($hasEmbeddedPassword ? '0' : '1'),
            'authentication level:i:2',
            'enablecredsspsupport:i:1',
        ]);

        $content = implode("\r\n", $lines)."\r\n";

        $filename = 'rdp-'.$hostingAccount->id.'.rdp';

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $filename, [
            'Content-Type' => 'application/x-rdp',
        ]);
    }

    /**
     * Encrypt a plain password for `password 51:b:` using Windows DPAPI via PowerShell.
     * Returns the hex/Base64 blob or null if encryption is unavailable (non-Windows / no PowerShell).
     * The blob is user/machine-bound — see rdpDownload docblock.
     */
    private function encryptRdpPassword(string $plain): ?string
    {
        // Use EncodedCommand (Base64 UTF-16LE) to avoid cmd.exe quoting hell.
        // Suppress progress bar CLIXML noise.
        $escaped = str_replace("'", "''", $plain);
        $psCommand = "\$ProgressPreference='SilentlyContinue';('".$escaped."' | ConvertTo-SecureString -AsPlainText -Force) | ConvertFrom-SecureString";
        $encoded = base64_encode(mb_convert_encoding($psCommand, 'UTF-16LE', 'UTF-8'));

        $candidates = ['powershell.exe', 'powershell', 'pwsh.exe', 'pwsh'];

        foreach ($candidates as $bin) {
            $cmd = $bin.' -NoProfile -NonInteractive -EncodedCommand '.$encoded.' 2>&1';
            $output = @shell_exec($cmd);
            if (! is_string($output) || trim($output) === '') {
                continue;
            }
            // PowerShell via shell_exec wraps output in CLIXML (#< CLIXML + <Objs> progress). Extract the pure hex line.
            foreach (preg_split('/\R/', $output) as $line) {
                $line = trim($line);
                if ($line === '' || $line === '#< CLIXML' || str_starts_with($line, '<Objs')) {
                    continue;
                }
                // Skip XML progress lines
                if (str_contains($line, '<Obj') || str_contains($line, 'Preparing modules')) {
                    continue;
                }
                $candidate = preg_replace('/\s+/', '', $line);
                if (is_string($candidate) && strlen($candidate) > 20 && preg_match('/^[0-9a-fA-F]+$/', $candidate)) {
                    return $candidate;
                }
            }
            // Fallback: joined output if line-splitting missed (single-line hex)
            $joined = preg_replace('/\s+/', '', $output);
            if (is_string($joined)) {
                // Strip known CLIXML wrappers
                $joined = str_replace('#<CLIXML', '', $joined);
                $joined = preg_replace('/<Objs.*$/s', '', $joined);
                $joined = trim($joined);
                if ($joined !== '' && strlen($joined) > 20 && preg_match('/^[0-9a-fA-F]+$/', $joined)) {
                    return $joined;
                }
            }
        }

        return null;
    }
}
