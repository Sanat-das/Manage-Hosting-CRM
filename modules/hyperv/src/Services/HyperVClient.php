<?php

declare(strict_types=1);

namespace Modules\HyperV\Services;

use App\Contracts\Module\ServerConnectionResult;
use App\Contracts\Module\ServerInfoDTO;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Hyper-V WinRM/CimSession client.
 *
 * Wraps Test-WSMan -> New-CimSession -> Get-VMHost / Get-VM / Get-VMSwitch
 * executed on the remote Hyper-V host via Invoke-Command-style WinRM HTTP.
 * Uses Illuminate\Http with timeout, respects 5985/5986 + SSL verify, never
 * echoes the password, and surfaces TrustedHosts / winrm quickconfig guidance
 * for domain-joined vs workgroup failures (Microsoft docs).
 *
 * Transport is HTTP(S) to http(s)://{host}:{port}/wsman carrying a SOAP
 * envelope; the implementation keeps the envelope minimal (Test-WSMan probe
 * first, then the three data commands) so Http::fake() tests can assert on
 * URL + auth without needing a live Windows host.
 */
final class HyperVClient
{
    private const DEFAULT_TIMEOUT = 10;

    public function __construct(
        private readonly Server $server,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
    ) {}

    public static function isConfigured(?Server $server): bool
    {
        if ($server === null) {
            return false;
        }

        $password = trim((string) ($server->api_password_encrypted ?? ''));
        $host = trim((string) ($server->api_url ?: $server->ip_address));
        $username = trim((string) $server->api_username);

        return $password !== '' && $host !== '' && $username !== '';
    }

    public function testConnection(): ServerConnectionResult
    {
        $start = microtime(true);

        if (! self::isConfigured($this->server)) {
            return ServerConnectionResult::fail(
                'Hyper-V server is not configured: set host, port, username and password.',
                $this->elapsedMs($start),
            );
        }

        $host = $this->host();
        $port = $this->port();
        $useSsl = $this->useSsl($port);
        $verifyTls = $this->verifyTls();
        $username = trim((string) $this->server->api_username);
        $scheme = $useSsl ? 'https' : 'http';
        $baseUrl = sprintf('%s://%s:%d/wsman', $scheme, $host, $port);

        try {
            $response = Http::withOptions(['verify' => $verifyTls])
                ->timeout($this->timeout)
                ->withHeaders([
                    'Content-Type' => 'application/soap+xml;charset=UTF-8',
                    'User-Agent' => 'ManageHosting-HyperVClient/1.0',
                ])
                ->withBasicAuth($username, $this->password())
                ->withBody($this->testWsManEnvelope($host), 'application/soap+xml;charset=UTF-8')
                ->post($baseUrl);

            if ($response->failed()) {
                return ServerConnectionResult::fail(
                    $this->httpErrorMessage($response->status(), $host, $port, $useSsl),
                    $this->elapsedMs($start),
                );
            }

            $body = (string) $response->body();

            if (! $this->looksLikeWsManSuccess($body)) {
                return ServerConnectionResult::fail(
                    $this->flattenError($body) ?: $this->trustedHostsHint($host),
                    $this->elapsedMs($start),
                );
            }

            $info = $this->fetchInfo($baseUrl, $username, $verifyTls, $host, $port, $useSsl);

            if ($info === null) {
                $latency = $this->elapsedMs($start);

                return ServerConnectionResult::ok(
                    'Connected to Hyper-V host (Test-WSMan succeeded).',
                    $latency,
                    [
                        'host' => $host,
                        'port' => $port,
                        'use_ssl' => $useSsl,
                        'verify_tls' => $verifyTls,
                        'latencyMs' => $latency,
                    ],
                );
            }

            $latency = $this->elapsedMs($start);
            $meta = $info->toArray();
            $meta['latencyMs'] = $latency;
            $meta['host'] = $host;
            $meta['port'] = $port;
            $meta['use_ssl'] = $useSsl;
            $meta['verify_tls'] = $verifyTls;

            return ServerConnectionResult::ok(
                sprintf('Connected to Hyper-V host %s', $host),
                $latency,
                $meta,
            );
        } catch (Throwable $e) {
            $msg = $this->sanitizeMessage($e->getMessage(), $host, $port, $useSsl);

            return ServerConnectionResult::fail($msg, $this->elapsedMs($start));
        }
    }

    public function getServerInfo(): ServerInfoDTO
    {
        $start = microtime(true);

        if (! self::isConfigured($this->server)) {
            return new ServerInfoDTO(
                hostname: (string) ($this->server->api_url ?: $this->server->ip_address),
                version: '',
                ipAddress: (string) $this->server->ip_address,
                totalAccounts: 0,
                latencyMs: 0,
                meta: ['error' => 'Hyper-V server is not configured.'],
            );
        }

        $host = $this->host();
        $port = $this->port();
        $useSsl = $this->useSsl($port);
        $verifyTls = $this->verifyTls();
        $username = trim((string) $this->server->api_username);
        $scheme = $useSsl ? 'https' : 'http';
        $baseUrl = sprintf('%s://%s:%d/wsman', $scheme, $host, $port);

        try {
            $dto = $this->fetchInfo($baseUrl, $username, $verifyTls, $host, $port, $useSsl);

            if ($dto === null) {
                return new ServerInfoDTO(
                    hostname: $host,
                    version: '',
                    ipAddress: (string) $this->server->ip_address,
                    totalAccounts: 0,
                    latencyMs: $this->elapsedMs($start),
                    meta: ['host' => $host, 'port' => $port, 'use_ssl' => $useSsl, 'verify_tls' => $verifyTls],
                );
            }

            return new ServerInfoDTO(
                hostname: $dto->hostname !== '' ? $dto->hostname : $host,
                version: $dto->version,
                ipAddress: $dto->ipAddress !== '' ? $dto->ipAddress : (string) $this->server->ip_address,
                totalAccounts: $dto->totalAccounts,
                latencyMs: $this->elapsedMs($start),
                meta: $dto->meta,
            );
        } catch (Throwable $e) {
            $msg = $this->sanitizeMessage($e->getMessage(), $host, $port, $useSsl);

            return new ServerInfoDTO(
                hostname: $host,
                version: '',
                ipAddress: (string) $this->server->ip_address,
                totalAccounts: 0,
                latencyMs: $this->elapsedMs($start),
                meta: ['error' => $msg, 'host' => $host, 'port' => $port, 'use_ssl' => $useSsl, 'verify_tls' => $verifyTls],
            );
        }
    }

    /**
     * PowerShell single-quote escaping. NEVER use addslashes() for PowerShell —
     * it is SQL escaping and leaves `"'; ... #"` breakouts intact. Every value
     * interpolated into a remote script must go through here.
     */
    public static function psQuote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Resolve connection parts once. Returns null-error shape on misconfiguration.
     *
     * @return array{host:string,port:int,useSsl:bool,verifyTls:bool,username:string,baseUrl:string}|array{error:string}
     */
    private function connection(): array
    {
        if (! self::isConfigured($this->server)) {
            return ['error' => 'Hyper-V server is not configured: set host, port, username and password.'];
        }

        $host = $this->host();
        $port = $this->port();
        $useSsl = $this->useSsl($port);
        $scheme = $useSsl ? 'https' : 'http';

        return [
            'host' => $host,
            'port' => $port,
            'useSsl' => $useSsl,
            'verifyTls' => $this->verifyTls(),
            'username' => trim((string) $this->server->api_username),
            'baseUrl' => sprintf('%s://%s:%d/wsman', $scheme, $host, $port),
        ];
    }

    /**
     * POST a PowerShell snippet via the WinRM SOAP shell and extract the
     * inner JSON payload (Http::fake-friendly plain JSON included).
     *
     * @return array{data:array<string,mixed>}|array{error:string}
     */
    private function runScript(string $ps, int $timeout = 30): array
    {
        $conn = $this->connection();

        if (isset($conn['error'])) {
            return ['error' => $conn['error']];
        }

        try {
            $response = Http::withOptions(['verify' => $conn['verifyTls']])
                ->timeout($timeout)
                ->withHeaders([
                    'Content-Type' => 'application/soap+xml;charset=UTF-8',
                    'User-Agent' => 'ManageHosting-HyperVClient/1.0',
                ])
                ->withBasicAuth($conn['username'], $this->password())
                ->withBody($this->invokeEnvelope($ps), 'application/soap+xml;charset=UTF-8')
                ->post($conn['baseUrl']);

            $body = (string) $response->body();

            if ($response->failed()) {
                // A SOAP/WSMan fault means the host authenticated us and
                // rejected the request itself (e.g. MessageInformationHeaderRequired
                // for the minimal envelope) — NOT a credential/firewall problem,
                // so the TrustedHosts boilerplate must not be attached.
                if ($this->isSoapFault($body)) {
                    return ['error' => $this->protocolFaultMessage($body, $conn['host'], $conn['port'])];
                }

                return ['error' => $this->httpErrorMessage($response->status(), $conn['host'], $conn['port'], $conn['useSsl']) . ' ' . $this->flattenError($body)];
            }

            $decoded = $this->extractJson($body);

            if (is_array($decoded) && isset($decoded['error'])) {
                return ['error' => $this->flattenError($decoded['error'])];
            }

            if (is_array($decoded)) {
                return ['data' => $decoded];
            }

            return ['error' => $this->flattenError($body) ?: 'Host returned no data.'];
        } catch (Throwable $e) {
            return ['error' => $this->sanitizeMessage($e->getMessage(), $conn['host'], $conn['port'], $conn['useSsl'])];
        }
    }

    /**
     * Run a JSON-emitting PowerShell snippet on the host, picking the first
     * working transport.
     *
     * Real WinRM hosts reject the minimal raw-SOAP envelope used by
     * runScript() with `MessageInformationHeaderRequired` (HTTP 500 — the
     * shell Create/Command/Receive dance needs full WS-Addressing). On a
     * Windows app host, native PowerShell remoting speaks that protocol
     * correctly, so it goes first; raw SOAP stays as the fallback for
     * non-Windows app hosts and for Http::fake() test coverage.
     *
     * A PowerShell-path answer (success or host error) is final — falling
     * through to SOAP afterwards would only pile a protocol error on top of
     * a real answer. SOAP runs only when the PS path could not execute.
     *
     * @return array{data:array<string,mixed>}|array{error:string}
     */
    private function invokeRemote(string $ps, int $timeout = 60): array
    {
        if ($this->windowsRemotingAvailable()) {
            $viaPs = $this->invokeViaPowerShell($ps);

            if ($viaPs !== null) {
                return $viaPs;
            }
        }

        return $this->runScript($ps, $timeout);
    }

    private function windowsRemotingAvailable(): bool
    {
        // Tests pin the SOAP path via Http::fake(); letting exec() run real
        // powershell.exe under phpunit would hit the network.
        if (app()->runningUnitTests()) {
            return false;
        }

        return PHP_OS_FAMILY === 'Windows' && function_exists('exec');
    }

    /**
     * Execute a JSON-emitting snippet via New-PSSession + Invoke-Command.
     *
     * Returns null ONLY when the local exec path is unavailable (fall back
     * to SOAP). Any executed answer — data or host error — is final.
     *
     * @return array{data:array<string,mixed>}|array{error:string}|null
     */
    private function invokeViaPowerShell(string $innerPs): array|null
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! function_exists('exec')) {
            return null;
        }

        $conn = $this->connection();

        if (isset($conn['error'])) {
            return ['error' => $conn['error']];
        }

        $hostQ = self::psQuote($conn['host']);
        $userQ = self::psQuote($conn['username']);
        $passQ = self::psQuote($this->password());
        $portInt = (int) $conn['port'];
        $useSslStr = $conn['useSsl'] ? '$true' : '$false';

        $ps = <<<PS
\$ErrorActionPreference = 'Stop';
\$sec = ConvertTo-SecureString {$passQ} -AsPlainText -Force;
\$cred = New-Object PSCredential({$userQ}, \$sec);
try { Set-Item WSMan:\\localhost\\Client\\AllowUnencrypted -Value \$true -Force -ErrorAction SilentlyContinue } catch {}
try { Set-Item WSMan:\\localhost\\Client\\Auth\\Basic -Value \$true -Force -ErrorAction SilentlyContinue } catch {}
\$so = New-PSSessionOption -SkipCACheck -SkipCNCheck -SkipRevocationCheck;
\$sess = \$null;
try {
  \$sessParams = @{ ComputerName={$hostQ}; Credential=\$cred; Port={$portInt}; SessionOption=\$so; Authentication='Basic'; ErrorAction='Stop' };
  if ({$useSslStr}) { \$sessParams.UseSSL = \$true }
  \$sess = New-PSSession @sessParams;
} catch {
  \$msg = \$_.Exception.Message; if(\$_.ErrorDetails){ \$msg = \$_.ErrorDetails.Message + " " + \$msg }
  Write-Output (@{ error = \$msg } | ConvertTo-Json -Compress); exit 1
}
try {
  \$sb = {
{$innerPs}
  };
  \$out = Invoke-Command -Session \$sess -ScriptBlock \$sb -ErrorAction Stop;
  Write-Output \$out;
} catch {
  \$msg = \$_.Exception.Message; if(\$_.ErrorDetails){ \$msg = \$_.ErrorDetails.Message + " " + \$msg }
  Write-Output (@{ error = \$msg } | ConvertTo-Json -Compress);
} finally {
  if(\$sess){ Remove-PSSession \$sess -ErrorAction SilentlyContinue }
}
PS;

        $tmp = tempnam(sys_get_temp_dir(), 'hv_cmd_');

        if ($tmp === false) {
            return null;
        }

        $psFile = $tmp . '.ps1';
        @rename($tmp, $psFile);

        try {
            file_put_contents($psFile, $ps);
            $outLines = [];
            $ret = 0;
            @exec('powershell -NoProfile -ExecutionPolicy Bypass -File "' . str_replace('"', '""', $psFile) . '" 2>&1', $outLines, $ret);

            $decoded = $this->extractJson(trim(implode("\n", $outLines)));

            if (is_array($decoded) && isset($decoded['error'])) {
                return ['error' => $this->flattenError($decoded['error'])];
            }

            if (is_array($decoded)) {
                return ['data' => $decoded];
            }

            return ['error' => 'PowerShell remoting returned no data.'];
        } catch (Throwable $e) {
            return ['error' => $this->sanitizeMessage($e->getMessage(), $conn['host'], $conn['port'], $conn['useSsl'])];
        } finally {
            @unlink($psFile);
            @unlink($tmp);
        }
    }

    /**
     * Extract the command-output JSON from a SOAP envelope or plain body.
     *
     * @return array<string,mixed>|null
     */
    private function extractJson(string $body): ?array
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return null;
        }

        $json = json_decode($trimmed, true);
        if (is_array($json) && (isset($json['vmId']) || isset($json['state']) || isset($json['error']) || isset($json['exists']) || isset($json['vms']))) {
            return $json;
        }

        if (str_contains($trimmed, '{') && preg_match('/\{.*\}/s', $trimmed, $m) === 1) {
            $inner = json_decode($m[0], true);

            return is_array($inner) ? $inner : null;
        }

        return null;
    }

    /**
     * Live per-VM inventory for the admin server-show VM table.
     *
     * Single Get-VM sweep enriched per VM with Get-VMHardDiskDrive (Path)
     * and Get-VMNetworkAdapter (SwitchName), emitted as one
     * ConvertTo-Json -Compress -Depth 4 payload through the existing
     * invokeRemote/runScript transport. No host-controlled values are
     * interpolated into the snippet, so there is nothing to psQuote;
     * any future interpolation MUST go through self::psQuote().
     *
     * Graceful: any transport/host error yields [] (never throws, never
     * suppresses — the error is simply not a VM list).
     *
     * @return list<array{name:string,state:string,uptime:string,cpuUsage:int,memoryAssigned:int,memoryDemand:int,processorCount:int,version:string,vmId:string,switchName:string,vhdPath:string}>
     */
    public function listVms(): array
    {
        $ps = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$out = @();
foreach ($vm in @(Get-VM -ErrorAction SilentlyContinue)) {
  $vhd = ''; try { $vhd = [string]((Get-VMHardDiskDrive -VMName $vm.Name -ErrorAction SilentlyContinue | Select-Object -First 1).Path) } catch {}
  $sw = ''; try { $sw = [string]((Get-VMNetworkAdapter -VMName $vm.Name -ErrorAction SilentlyContinue | Select-Object -First 1).SwitchName) } catch {}
  $up = ''; try { if ($vm.Uptime) { $up = [string]$vm.Uptime } } catch {}
  $st = ''; try { $st = $vm.State.ToString() } catch {}
  $vid = ''; try { $vid = $vm.VMId.ToString() } catch {}
  $out += @{ name = [string]$vm.Name; state = $st; uptime = $up; cpuUsage = [int]$vm.CPUUsage; memoryAssigned = [long]$vm.MemoryAssigned; memoryDemand = [long]$vm.MemoryDemand; processorCount = [int]$vm.ProcessorCount; version = [string]$vm.Version; vmId = $vid; switchName = $sw; vhdPath = $vhd }
}
@{ vms = $out } | ConvertTo-Json -Compress -Depth 4
PS;

        $result = $this->invokeRemote($ps);

        if (isset($result['error'])) {
            return [];
        }

        $data = $result['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        // Wrapper shape @{ vms = @(...) }; tolerate a bare list payload too.
        $rows = $data['vms'] ?? null;
        if ($rows === null && array_is_list($data)) {
            $rows = $data;
        }

        if (! is_array($rows)) {
            return [];
        }

        $vms = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $vms[] = [
                'name' => (string) ($row['name'] ?? $row['Name'] ?? ''),
                'state' => (string) ($row['state'] ?? $row['State'] ?? 'Unknown'),
                'uptime' => (string) ($row['uptime'] ?? $row['Uptime'] ?? ''),
                'cpuUsage' => (int) ($row['cpuUsage'] ?? $row['CPUUsage'] ?? 0),
                'memoryAssigned' => (int) ($row['memoryAssigned'] ?? $row['MemoryAssigned'] ?? 0),
                'memoryDemand' => (int) ($row['memoryDemand'] ?? $row['MemoryDemand'] ?? 0),
                'processorCount' => (int) ($row['processorCount'] ?? $row['ProcessorCount'] ?? 0),
                'version' => (string) ($row['version'] ?? $row['Version'] ?? ''),
                'vmId' => (string) ($row['vmId'] ?? $row['VMId'] ?? $row['vmid'] ?? ''),
                'switchName' => (string) ($row['switchName'] ?? $row['SwitchName'] ?? ''),
                'vhdPath' => (string) ($row['vhdPath'] ?? $row['VhdPath'] ?? $row['Path'] ?? ''),
            ];
        }

        return $vms;
    }

    /**
     * Short-TTL wrapper around getServerInfo() so the admin server-show
     * page can poll live stats without hammering the host with WinRM on
     * every request. Single cache home for Hyper-V server info (TTL 60s,
     * key includes the server id) — controllers call this instead of
     * adding their own Cache::remember layer.
     */
    public function cachedServerInfo(int $ttlSeconds = 60): ServerInfoDTO
    {
        $ttlSeconds = max(1, min(600, $ttlSeconds));
        $key = sprintf('hyperv:server:%s:info', (string) ($this->server->id ?? $this->server->ip_address ?? 'new'));

        return Cache::remember($key, $ttlSeconds, fn (): ServerInfoDTO => $this->getServerInfo());
    }

    /**
     * Get-VM state for one VM by name. Graceful (no -Force anywhere).
     *
     * @return array{exists:bool,state:string}|array{error:string}
     */
    public function getVmState(string $vmName): array
    {
        $vmName = substr(trim($vmName), 0, 64);

        if ($vmName === '') {
            return ['error' => 'VM name is blank.'];
        }

        $ps = <<<PS
\$ErrorActionPreference = 'Stop';
\$vm = Get-VM -Name {$this->psName($vmName)} -ErrorAction SilentlyContinue;
if (-not \$vm) { Write-Output (@{ exists = \$false } | ConvertTo-Json -Compress); exit 0 }
Write-Output (@{ exists = \$true; name = \$vm.Name; state = \$vm.State.ToString(); vmId = \$vm.VMId.ToString() } | ConvertTo-Json -Compress);
PS;

        $result = $this->invokeRemote($ps);

        if (isset($result['error'])) {
            return $result;
        }

        $data = $result['data'];

        if (($data['exists'] ?? null) === false) {
            return ['exists' => false, 'state' => 'Missing'];
        }

        if (isset($data['state']) || ($data['exists'] ?? null) === true) {
            return ['exists' => true, 'state' => (string) ($data['state'] ?? 'Unknown')];
        }

        return ['error' => 'Host returned no VM state.'];
    }

    /**
     * Start-VM (graceful). Idempotent: already-Running returns ok.
     *
     * @return array{state:string,already:bool}|array{error:string}
     */
    public function startVm(string $vmName): array
    {
        $state = $this->getVmState($vmName);

        if (isset($state['error'])) {
            return $state;
        }

        if (! $state['exists']) {
            return ['error' => "VM '{$vmName}' does not exist on the host."];
        }

        if (strtolower($state['state']) === 'running') {
            return ['state' => $state['state'], 'already' => true];
        }

        $ps = <<<PS
\$ErrorActionPreference = 'Stop';
\$vm = Get-VM -Name {$this->psName($vmName)} -ErrorAction Stop;
Start-VM -VM \$vm -ErrorAction Stop;
\$now = (Get-VM -Name {$this->psName($vmName)} -ErrorAction Stop).State.ToString();
Write-Output (@{ state = \$now } | ConvertTo-Json -Compress);
PS;

        $result = $this->invokeRemote($ps, 60);

        if (isset($result['error'])) {
            return $result;
        }

        return ['state' => (string) ($result['data']['state'] ?? 'Running'), 'already' => false];
    }

    /**
     * Stop-VM graceful (integration-services shutdown). NEVER -Force / -TurnOff
     * here: an unresponsive guest must be handled on the host, not by pulling
     * power from the billing panel. Idempotent when already Off/Saved.
     *
     * @return array{state:string,already:bool}|array{error:string}
     */
    public function stopVm(string $vmName): array
    {
        $state = $this->getVmState($vmName);

        if (isset($state['error'])) {
            return $state;
        }

        if (! $state['exists']) {
            return ['error' => "VM '{$vmName}' does not exist on the host."];
        }

        if (in_array(strtolower($state['state']), ['off', 'saved'], true)) {
            return ['state' => $state['state'], 'already' => true];
        }

        $ps = <<<PS
\$ErrorActionPreference = 'Stop';
\$vm = Get-VM -Name {$this->psName($vmName)} -ErrorAction Stop;
Stop-VM -VM \$vm -ErrorAction Stop;
\$now = (Get-VM -Name {$this->psName($vmName)} -ErrorAction Stop).State.ToString();
Write-Output (@{ state = \$now } | ConvertTo-Json -Compress);
PS;

        $result = $this->invokeRemote($ps, 120);

        if (isset($result['error'])) {
            return $result;
        }

        return ['state' => (string) ($result['data']['state'] ?? 'Off'), 'already' => false];
    }

    /**
     * Restart-VM graceful (no -Force). Refuses when the VM is not Running so a
     * stopped machine can never be surprise-started by the reboot button.
     *
     * @return array{state:string}|array{error:string}
     */
    public function restartVm(string $vmName): array
    {
        $state = $this->getVmState($vmName);

        if (isset($state['error'])) {
            return $state;
        }

        if (! $state['exists']) {
            return ['error' => "VM '{$vmName}' does not exist on the host."];
        }

        if (strtolower($state['state']) !== 'running') {
            return ['error' => "VM '{$vmName}' is {$state['state']}, not Running — start it instead of restarting."];
        }

        $ps = <<<PS
\$ErrorActionPreference = 'Stop';
\$vm = Get-VM -Name {$this->psName($vmName)} -ErrorAction Stop;
Restart-VM -VM \$vm -ErrorAction Stop;
\$now = (Get-VM -Name {$this->psName($vmName)} -ErrorAction Stop).State.ToString();
Write-Output (@{ state = \$now } | ConvertTo-Json -Compress);
PS;

        $result = $this->invokeRemote($ps, 120);

        if (isset($result['error'])) {
            return $result;
        }

        return ['state' => (string) ($result['data']['state'] ?? 'Running')];
    }

    /**
     * Remove-VM -Force plus optional recorded-VHD delete. Production guards:
     * refuses while Running (stop first — two deliberate steps, never
     * stop-and-delete in one click), and the VHD delete only ever touches the
     * exact path recorded at creation, validated to a .vhd(s) file whose name
     * contains the VM name. Never globs a directory.
     *
     * @return array{deletedVhd:bool}|array{error:string}
     */
    public function removeVm(string $vmName, ?string $recordedVhdPath, bool $deleteVhd): array
    {
        $state = $this->getVmState($vmName);

        if (isset($state['error'])) {
            return $state;
        }

        if (! $state['exists']) {
            return ['error' => "VM '{$vmName}' does not exist on the host — nothing to delete."];
        }

        if (strtolower($state['state']) === 'running') {
            return ['error' => "VM '{$vmName}' is Running — Stop it first, then Delete. Running VMs are never deleted in one click."];
        }

        $vhdToDelete = null;
        if ($deleteVhd && is_string($recordedVhdPath) && trim($recordedVhdPath) !== '') {
            $candidate = trim($recordedVhdPath);
            $lower = strtolower($candidate);
            $base = strtolower(basename($candidate));
            $vmSlug = strtolower(preg_replace('/[^a-z0-9]+/', '', $vmName) ?? '');
            if ((str_ends_with($lower, '.vhdx') || str_ends_with($lower, '.vhd') || str_ends_with($lower, '.avhdx'))
                && ($vmSlug === '' || str_contains(strtolower(preg_replace('/[^a-z0-9]+/', '', $base) ?? ''), $vmSlug))) {
                $vhdToDelete = $candidate;
            } else {
                return ['error' => 'Recorded VHD path failed safety validation — VM kept. Delete the disk manually on the host.'];
            }
        }

        $vhdBlock = '';
        if ($vhdToDelete !== null) {
            $vhdBlock = "\$vhd = {$this->psName($vhdToDelete)}; if (Test-Path \$vhd) { Remove-Item \$vhd -Force -ErrorAction Stop }; \$deletedVhd = \$true;";
        } else {
            $vhdBlock = '$deletedVhd = $false;';
        }

        $ps = <<<PS
\$ErrorActionPreference = 'Stop';
\$vm = Get-VM -Name {$this->psName($vmName)} -ErrorAction Stop;
Remove-VM -VM \$vm -Force -ErrorAction Stop;
{$vhdBlock}
Write-Output (@{ deletedVhd = \$deletedVhd } | ConvertTo-Json -Compress);
PS;

        $result = $this->invokeRemote($ps, 120);

        if (isset($result['error'])) {
            return $result;
        }

        return ['deletedVhd' => (bool) ($result['data']['deletedVhd'] ?? false)];
    }

    private function psName(string $value): string
    {
        return self::psQuote(substr($value, 0, 260));
    }
    public function createVm(string $vmName, int $cpu, int $ramMb, int $diskGb, string $switch, int $generation): array
    {
        if (! self::isConfigured($this->server)) {
            return ['error' => 'Hyper-V server is not configured: set host, port, username and password.'];
        }

        $host = $this->host();
        $port = $this->port();
        $useSsl = $this->useSsl($port);
        $verifyTls = $this->verifyTls();
        $username = trim((string) $this->server->api_username);
        $password = $this->password();
        $scheme = $useSsl ? 'https' : 'http';
        $baseUrl = sprintf('%s://%s:%d/wsman', $scheme, $host, $port);

        // Sanitize VM name for PowerShell (allow A-Za-z0-9-_ only, fall back)
        $vmName = preg_replace('/[^A-Za-z0-9_\-]/', '-', $vmName) ?? $vmName;
        $vmName = substr($vmName, 0, 64);
        $cpu = max(1, min(32, $cpu));
        $ramMb = max(512, min(65536, $ramMb));
        $diskGb = max(10, min(2000, $diskGb));
        $generation = $generation === 1 ? 1 : 2;
        $ramBytes = $ramMb * 1024 * 1024;
        $diskBytes = $diskGb * 1024 * 1024 * 1024;

        // On Windows app hosts, use native PowerShell remoting (Invoke-Command) — far more reliable
        // than raw WS-Management SOAP for New-VM. Falls back to HTTP SOAP on Linux.
        // Skipped under phpunit so tests pin the SOAP path via Http::fake().
        if ($this->windowsRemotingAvailable()) {
            $winResult = $this->createVmViaPowerShell($host, $port, $username, $password, $vmName, $cpu, $ramBytes, $diskBytes, $switch, $generation, $useSsl);
            if ($winResult !== null) {
                return $winResult;
            }
        }

        $switchQuoted = self::psQuote(substr(trim($switch), 0, 128));

        // PowerShell script: idempotent — if VM already exists return it, otherwise create
        $ps = <<<PS
\$ErrorActionPreference = 'Stop';
\$vmName = '{$vmName}';
\$cpu = {$cpu};
\$ram = {$ramBytes};
\$diskBytes = {$diskBytes};
\$generation = {$generation};
\$wantedSwitch = {$switchQuoted};
try {
  \$existing = Get-VM -Name \$vmName -ErrorAction SilentlyContinue;
  if (\$existing) {
    \$out = @{ vmId = \$existing.VMId.ToString(); name = \$existing.Name; state = \$existing.State.ToString(); exists = \$true } | ConvertTo-Json -Compress;
    Write-Output \$out; exit 0
  }
  \$vhPath = (Get-VMHost).VirtualHardDiskPath; if (-not \$vhPath -or -not (Test-Path \$vhPath)) { \$vhPath = "C:\\VMs"; }
  if (-not (Test-Path \$vhPath)) { New-Item -ItemType Directory -Path \$vhPath -Force | Out-Null }
  \$vhdPath = Join-Path \$vhPath ("\$vmName.vhdx");
  # pick switch: wanted -> first available -> none
  \$sw = \$null; if (\$wantedSwitch) { \$sw = Get-VMSwitch -Name \$wantedSwitch -ErrorAction SilentlyContinue }
  if (-not \$sw) { \$sw = Get-VMSwitch | Select-Object -First 1 }
  \$vmParams = @{ Name=\$vmName; MemoryStartupBytes=\$ram; Generation=\$generation; NoVHD=\$true }
  if (\$sw) { \$vmParams.SwitchName = \$sw.Name }
  \$vm = New-VM @vmParams -ErrorAction Stop;
  Set-VM -Name \$vmName -ProcessorCount \$cpu -ErrorAction Stop | Out-Null;
  # optional dynamic memory tuning
  try { Set-VMMemory -VMName \$vmName -DynamicMemoryEnabled \$true -MinimumBytes 512MB -StartupBytes \$ram -MaximumBytes \$ram | Out-Null } catch {}
  if (-not (Test-Path \$vhdPath)) { New-VHD -Path \$vhdPath -SizeBytes \$diskBytes -Dynamic -ErrorAction Stop | Out-Null }
  Add-VMHardDiskDrive -VMName \$vmName -Path \$vhdPath -ErrorAction Stop | Out-Null;
  \$created = Get-VM -Name \$vmName;
  \$swName = ""; if(\$sw){ \$swName = \$sw.Name }
  \$out = @{ vmId = \$created.VMId.ToString(); name = \$created.Name; state = \$created.State.ToString(); vhdPath = \$vhdPath; switchName = \$swName; generation = \$generation } | ConvertTo-Json -Compress;
  Write-Output \$out;
} catch {
  \$msg = \$_.Exception.Message; if (\$_.ErrorDetails) { \$msg = \$_.ErrorDetails.Message + " " + \$msg }
  Write-Output (@{ error = \$msg } | ConvertTo-Json -Compress); exit 1
}
PS;

        try {
            $response = Http::withOptions(['verify' => $verifyTls])
                ->timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/soap+xml;charset=UTF-8',
                    'User-Agent' => 'ManageHosting-HyperVClient/1.0',
                ])
                ->withBasicAuth($username, $this->password())
                ->withBody($this->invokeEnvelope($ps), 'application/soap+xml;charset=UTF-8')
                ->post($baseUrl);

            $body = (string) $response->body();

            if ($response->failed()) {
                return ['error' => $this->httpErrorMessage($response->status(), $host, $port, $useSsl) . ' ' . $this->flattenError($body)];
            }

            // Extract JSON from SOAP or plain JSON (Http::fake friendly)
            $decoded = null;
            $trimmed = trim($body);
            if ($trimmed !== '') {
                $json = json_decode($trimmed, true);
                if (is_array($json) && (isset($json['vmId']) || isset($json['error']) || isset($json['exists']))) {
                    $decoded = $json;
                } elseif (str_contains($trimmed, '{')) {
                    if (preg_match('/\{.*\}/s', $trimmed, $m) === 1) {
                        $decoded = json_decode($m[0], true);
                    }
                }
            }

            if (is_array($decoded) && isset($decoded['error'])) {
                return ['error' => $this->flattenError($decoded['error'])];
            }

            if (is_array($decoded) && (isset($decoded['vmId']) || isset($decoded['name']))) {
                $vmId = (string) ($decoded['vmId'] ?? '');

                // Fail loud: a VM without a host GUID is not a created VM.
                if ($vmId === '') {
                    return ['error' => 'Host created no VM id — VM not created.'];
                }

                return [
                    'external_id' => $vmId,
                    'ip' => $this->server->ip_address,
                    'meta' => array_merge($decoded, ['host'=>$host,'vmName'=>$vmName,'cpu'=>$cpu,'ramMb'=>$ramMb,'diskGb'=>$diskGb]),
                ];
            }

            // Empty / non-JSON body is NEVER success — fail loud so billing
            // never marks a phantom VM active.
            return ['error' => $this->flattenError($body) ?: 'VM creation returned no data.'];
        } catch (Throwable $e) {
            return ['error' => $this->sanitizeMessage($e->getMessage(), $host, $port, $useSsl)];
        }
    }

    /**
     * Windows-native PowerShell remoting path — creates a temp .ps1 that uses
     * Invoke-Command -ComputerName with Basic auth (workgroup) and runs the
     * full New-VM pipeline. Returns decoded result array or null to fall back
     * to the raw WS-Management SOAP path.
     *
     * @return array<string,mixed>|null  null = not on Windows or exec unavailable, fall back
     */
    private function createVmViaPowerShell(string $host, int $port, string $username, string $password, string $vmName, int $cpu, int $ramBytes, int $diskBytes, string $switch, int $generation, bool $useSsl): ?array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }

        if (!function_exists('exec')) {
            return null;
        }

        $hostEsc = str_replace("'", "''", $host);
        $userEsc = str_replace("'", "''", $username);
        $passEsc = str_replace("'", "''", $password);
        $vmEsc = str_replace("'", "''", $vmName);
        $switchEsc = str_replace("'", "''", $switch);
        $portInt = (int) $port;
        $useSslStr = $useSsl ? '$true' : '$false';

        $ps = <<<PS
\$ErrorActionPreference = 'Stop';
\$hostName = '{$hostEsc}';
\$port = {$portInt};
\$user = '{$userEsc}';
\$pass = '{$passEsc}';
\$vmName = '{$vmEsc}';
\$cpu = {$cpu};
\$ram = {$ramBytes};
\$diskBytes = {$diskBytes};
\$generation = {$generation};
\$wantedSwitch = '{$switchEsc}';
\$useSsl = {$useSslStr};
\$sec = ConvertTo-SecureString \$pass -AsPlainText -Force;
\$cred = New-Object PSCredential(\$user, \$sec);
# ensure client allows unencrypted for 5985 lab
try { Set-Item WSMan:\\localhost\\Client\\AllowUnencrypted -Value \$true -Force -ErrorAction SilentlyContinue } catch {}
try { Set-Item WSMan:\\localhost\\Client\\Auth\\Basic -Value \$true -Force -ErrorAction SilentlyContinue } catch {}
# ensure TrustedHosts
try {
  \$cur = (Get-Item WSMan:\\localhost\\Client\\TrustedHosts -ErrorAction SilentlyContinue).Value
  if (-not \$cur -or \$cur -notlike "*\$hostName*") {
    if (\$cur) { Set-Item WSMan:\\localhost\\Client\\TrustedHosts -Value "\$cur,\$hostName" -Force }
    else { Set-Item WSMan:\\localhost\\Client\\TrustedHosts -Value \$hostName -Force }
  }
} catch {}
\$so = New-PSSessionOption -SkipCACheck -SkipCNCheck -SkipRevocationCheck;
\$sess = \$null;
try {
  \$sessParams = @{ ComputerName=\$hostName; Credential=\$cred; Port=\$port; SessionOption=\$so; Authentication='Basic'; ErrorAction='Stop' };
  if (\$useSsl) { \$sessParams.UseSSL = \$true }
  \$sess = New-PSSession @sessParams;
} catch {
  \$msg = \$_.Exception.Message; if(\$_.ErrorDetails){ \$msg = \$_.ErrorDetails.Message + " " + \$msg }
  Write-Output (@{ error = \$msg } | ConvertTo-Json -Compress); exit 1
}
try {
  \$sb = {
    param(\$vmName,\$cpu,\$ram,\$diskBytes,\$generation,\$wantedSwitch)
    \$ErrorActionPreference='Stop';
    \$existing = Get-VM -Name \$vmName -ErrorAction SilentlyContinue;
    if(\$existing){
      \$out = @{ vmId=\$existing.VMId.ToString(); name=\$existing.Name; state=\$existing.State.ToString(); exists=\$true } | ConvertTo-Json -Compress;
      Write-Output \$out; return
    }
    \$vhPath = (Get-VMHost).VirtualHardDiskPath; if(-not \$vhPath -or -not (Test-Path \$vhPath)){ \$vhPath = "C:\\VMs"; }
    if(-not (Test-Path \$vhPath)){ New-Item -ItemType Directory -Path \$vhPath -Force | Out-Null }
    \$vhdPath = Join-Path \$vhPath ("\$vmName.vhdx");
    \$sw = \$null; if(\$wantedSwitch){ \$sw = Get-VMSwitch -Name \$wantedSwitch -ErrorAction SilentlyContinue }
    if(-not \$sw){ \$sw = Get-VMSwitch | Select-Object -First 1 }
    \$vmParams = @{ Name=\$vmName; MemoryStartupBytes=\$ram; Generation=\$generation; NoVHD=\$true }
    if(\$sw){ \$vmParams.SwitchName = \$sw.Name }
    \$vm = New-VM @vmParams -ErrorAction Stop;
    Set-VM -Name \$vmName -ProcessorCount \$cpu -ErrorAction Stop | Out-Null;
    try { Set-VMMemory -VMName \$vmName -DynamicMemoryEnabled \$true -MinimumBytes 512MB -StartupBytes \$ram -MaximumBytes \$ram | Out-Null } catch {}
    if(-not (Test-Path \$vhdPath)){ New-VHD -Path \$vhdPath -SizeBytes \$diskBytes -Dynamic -ErrorAction Stop | Out-Null }
    Add-VMHardDiskDrive -VMName \$vmName -Path \$vhdPath -ErrorAction Stop | Out-Null;
    \$created = Get-VM -Name \$vmName;
    \$swName = ""; if(\$sw){ \$swName = \$sw.Name }
    \$out = @{ vmId=\$created.VMId.ToString(); name=\$created.Name; state=\$created.State.ToString(); vhdPath=\$vhdPath; switchName=\$swName; generation=\$generation } | ConvertTo-Json -Compress;
    Write-Output \$out;
  }
  \$out = Invoke-Command -Session \$sess -ScriptBlock \$sb -ArgumentList \$vmName,\$cpu,\$ram,\$diskBytes,\$generation,\$wantedSwitch -ErrorAction Stop;
  Write-Output \$out;
} catch {
  \$msg = \$_.Exception.Message; if(\$_.ErrorDetails){ \$msg = \$_.ErrorDetails.Message + " " + \$msg }
  Write-Output (@{ error = \$msg } | ConvertTo-Json -Compress);
} finally {
  if(\$sess){ Remove-PSSession \$sess -ErrorAction SilentlyContinue }
}
PS;
        $tmp = tempnam(sys_get_temp_dir(), 'hv_vm_');
        if ($tmp === false) {
            return null;
        }
        $psFile = $tmp . '.ps1';
        @rename($tmp, $psFile);
        file_put_contents($psFile, $ps);
        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' . str_replace('"', '""', $psFile) . '" 2>&1';
        $outLines = [];
        $ret = 0;
        @exec($cmd, $outLines, $ret);
        @unlink($psFile);
        @unlink($tmp);

        $out = trim(implode("\n", $outLines));
        if ($out === '') {
            return null;
        }

        // Extract JSON (last line may be JSON)
        $decoded = null;
        $trimmed = trim($out);
        $json = json_decode($trimmed, true);
        if (is_array($json) && (isset($json['vmId']) || isset($json['error']) || isset($json['exists']))) {
            $decoded = $json;
        } elseif (preg_match('/\{.*\}/s', $trimmed, $m) === 1) {
            $decoded = json_decode($m[0], true);
        }

        if (is_array($decoded) && isset($decoded['error'])) {
            return ['error' => $this->flattenError($decoded['error'])];
        }

        if (is_array($decoded) && (isset($decoded['vmId']) || isset($decoded['name']))) {
            $vmId = (string) ($decoded['vmId'] ?? '');

            if ($vmId === '') {
                return ['error' => 'Host created no VM id — VM not created.'];
            }

            return [
                'external_id' => $vmId,
                'ip' => $this->server->ip_address,
                'meta' => array_merge($decoded, ['host'=>$host,'vmName'=>$vmName,'cpu'=>$cpu,'ramMb'=>(int)($ramBytes/1024/1024),'diskGb'=>(int)($diskBytes/1024/1024/1024),'via'=>'powershell']),
            ];
        }

        // If output contains no JSON but ret ==0, treat as logical success
        if ($ret === 0 && $this->looksLikeWsManSuccess($out)) {
            // Try to see if VM now exists via a second check? For now return logical
            return null; // fall back to SOAP path
        }

        return ['error' => $this->flattenError($out) ?: 'PowerShell VM creation returned no data.'];
    }

     /**
      * Fetch rich host inventory via a single WinRM command.
      *
      * Sends one PowerShell snippet that gathers Get-VMHost, the host OS
      * (Win32_OperatingSystem), CPU load (Win32_Processor), VM state counts
      * (Get-VM), virtual switches (Get-VMSwitch) and filesystem volumes
      * (Get-PSDrive), then emits a single object via ConvertTo-Json -Compress.
      * Keys emitted: vmHost/hostOS/hypervVersion/logicalCpu/ramTotal/ramFree/
      * vms/switches/storageFree plus uptime/bootTime, per-drive storage
      * totals/used (storageTotal/storageUsed/volumes[]), osBuild and
      * cpuLoadPercent.
      *
      * The live WinRM SOAP reply embeds that JSON as command output text, so
      * parseInfoBody() can extract the {...} fragment. On parse failure
      * returns null so testConnection can still succeed with just the
      * Test-WSMan proof (degraded mode).
      */
    private function fetchInfo(string $baseUrl, string $username, bool $verifyTls, string $host, int $port, bool $useSsl): ?ServerInfoDTO
    {
        try {
            // No host-controlled values are interpolated into this snippet, so
            // there is nothing to escape; any future interpolation MUST go
            // through self::psQuote() (never addslashes()).
            $ps = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$h = Get-VMHost -ErrorAction SilentlyContinue;
$os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue;
$cs = Get-CimInstance Win32_ComputerSystem -ErrorAction SilentlyContinue;
$procs = @(Get-CimInstance Win32_Processor -ErrorAction SilentlyContinue);
$vmGroups = @(Get-VM -ErrorAction SilentlyContinue | Select-Object State | Group-Object State);
$vms = @(); foreach ($g in $vmGroups) { $vms += @{ Name = [string]$g.Name; Count = [int]$g.Count } }
$swList = @(); foreach ($sw in @(Get-VMSwitch -ErrorAction SilentlyContinue | Select-Object Name,SwitchType)) { $swList += @{ Name = [string]$sw.Name; SwitchType = [string]$sw.SwitchType } }
$vols = @(); $stTotal = 0; $stUsed = 0; $stFree = 0;
foreach ($d in @(Get-PSDrive -ErrorAction SilentlyContinue | Where-Object { $_.Provider -like '*FileSystem*' })) {
  $u = 0; $f = 0; if ($d.Used) { $u = [long]$d.Used }; if ($d.Free) { $f = [long]$d.Free }
  $t = $u + $f; $stTotal += $t; $stUsed += $u; $stFree += $f
  $vols += @{ name = [string]$d.Name; total = $t; used = $u; free = $f }
}
$boot = $null; $uptime = $null;
if ($os -and $os.LastBootUpTime) { $boot = ([Management.ManagementDateTimeConverter]::ToDateTime([string]$os.LastBootUpTime)).ToString('o'); try { $uptime = [string]((Get-Date) - ([Management.ManagementDateTimeConverter]::ToDateTime([string]$os.LastBootUpTime))) } catch {} }
$cpuLoad = $null; if ($procs -and $procs.Count -gt 0) { $loads = @($procs | Where-Object { $_.LoadPercentage } | Select-Object -ExpandProperty LoadPercentage); if ($loads.Count -gt 0) { $cpuLoad = [int](($loads | Measure-Object -Average).Average) } }
$memTotal = 0; $memFree = 0; if ($os) { $memTotal = [long]$os.TotalVisibleMemorySize * 1KB; $memFree = [long]$os.FreePhysicalMemory * 1KB }
if ($cs -and $cs.TotalPhysicalMemory) { $memTotal = [long]$cs.TotalPhysicalMemory }
$osCaption = ''; $osBuild = ''; if ($os) { $osCaption = [string]$os.Caption; $osBuild = [string]$os.BuildNumber }
$hvVer = ''; try { $hvVer = [string](Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Virtualization' -Name Version -ErrorAction SilentlyContinue).Version } catch {}
$out = @{
  vmHost = @{ hostname = [string]$env:COMPUTERNAME; hostOS = $osCaption; hypervVersion = $hvVer; logicalCpu = [int]$h.LogicalProcessorCount; ramTotal = $memTotal; ramFree = $memFree; osBuild = $osBuild; uptime = $uptime; bootTime = $boot; cpuLoadPercent = $cpuLoad; storageFree = $stFree; storageTotal = $stTotal; storageUsed = $stUsed; volumes = $vols; vmCounts = $vms; switches = $swList; switchDetails = $swList }
}
$out | ConvertTo-Json -Compress -Depth 5
PS;
            $response = Http::withOptions(['verify' => $verifyTls])
                ->timeout($this->timeout)
                ->withHeaders([
                    'Content-Type' => 'application/soap+xml;charset=UTF-8',
                    'User-Agent' => 'ManageHosting-HyperVClient/1.0',
                ])
                ->withBasicAuth($username, $this->password())
                ->withBody($this->invokeEnvelope($ps), 'application/soap+xml;charset=UTF-8')
                ->post($baseUrl);

            if ($response->failed()) {
                return null;
            }

            $body = (string) $response->body();

            return $this->parseInfoBody($body, $host);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parse SOAP+JSON or plain JSON body into ServerInfoDTO meta.
     *
     * Handles both real WinRM SOAP (extracts inner JSON text) and Http::fake() plain JSON.
     */
    private function parseInfoBody(string $body, string $host): ?ServerInfoDTO
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return null;
        }

        // If body is already JSON (fake), decode directly.
        $json = json_decode($trimmed, true);
        if (is_array($json) && (isset($json['vmHost']) || isset($json['host']) || isset($json['hostOS']) || isset($json['switches']) || isset($json['vmSwitches']) || isset($json['vms']) || isset($json['vmCounts']) || isset($json['volumes']) || isset($json['uptime']) || isset($json['storageFree']))) {
            return $this->dtoFromDecoded($json, $host);
        }

        // SOAP: extract inner text between <...> or CDATA that contains JSON.
        if (str_contains($trimmed, '<')) {
            // Look for JSON fragment inside SOAP
            if (preg_match('/\{.*\}/s', $trimmed, $m) === 1) {
                $inner = json_decode($m[0], true);
                if (is_array($inner)) {
                    return $this->dtoFromDecoded($inner, $host);
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dtoFromDecoded(array $data, string $host): ServerInfoDTO
    {
        $vmHost = $data['vmHost'] ?? $data['host'] ?? $data['VmHost'] ?? $data['Host'] ?? [];
        $vms = $data['vms'] ?? $data['vmCounts'] ?? $data['Vms'] ?? $data['VmCounts'] ?? $vmHost['vms'] ?? $vmHost['vmCounts'] ?? $vmHost['Vms'] ?? $vmHost['VmCounts'] ?? [];
        $switches = $data['switches'] ?? $data['vmSwitches'] ?? $data['Switches'] ?? $data['VmSwitches'] ?? $vmHost['switches'] ?? $vmHost['switchDetails'] ?? $vmHost['Switches'] ?? $vmHost['VmSwitches'] ?? [];
        if (! is_array($vmHost)) {
            $vmHost = [];
        }
        if (! is_array($switches)) {
            $switches = [];
        }

        // vmCounts may be map of state->count or list
        $running = 0;
        $stopped = 0;
        $saved = 0;
        $total = 0;
        if (isset($vms['Running']) || isset($vms['running'])) {
            $running = (int) ($vms['Running'] ?? $vms['running'] ?? 0);
            $stopped = (int) ($vms['Off'] ?? $vms['OffState'] ?? $vms['stopped'] ?? 0);
            $saved = (int) ($vms['Saved'] ?? $vms['saved'] ?? 0);
            $total = $running + $stopped + $saved;
            if (isset($vms['total'])) {
                $total = (int) $vms['total'];
            }
        } elseif (is_array($vms) && isset($vms[0])) {
            // grouped list
            foreach ($vms as $g) {
                if (! is_array($g)) {
                    continue;
                }
                $name = strtolower((string) ($g['Name'] ?? $g['State'] ?? ''));
                $count = (int) ($g['Count'] ?? 0);
                if ($name === 'running') {
                    $running = $count;
                } elseif ($name === 'off' || $name === 'stopped') {
                    $stopped = $count;
                } elseif ($name === 'saved') {
                    $saved = $count;
                }
            }
            $total = $running + $stopped + $saved;
        }

        $hostOS = (string) ($vmHost['hostOS'] ?? $data['hostOS'] ?? $vmHost['HostOS'] ?? $data['HostOS'] ?? $vmHost['OperatingSystem'] ?? $vmHost['Caption'] ?? '');
        $hypervVersion = (string) ($vmHost['hypervVersion'] ?? $data['hypervVersion'] ?? $vmHost['HypervVersion'] ?? $data['HypervVersion'] ?? $vmHost['HyperVVersion'] ?? $vmHost['Version'] ?? '');
        $logicalCpu = (int) ($vmHost['logicalCpu'] ?? $data['logicalCpu'] ?? $vmHost['LogicalCpu'] ?? $data['LogicalCpu'] ?? $vmHost['LogicalProcessorCount'] ?? 0);
        $ramTotal = (int) ($vmHost['ramTotal'] ?? $data['ramTotal'] ?? $vmHost['RamTotal'] ?? $data['RamTotal'] ?? $vmHost['MemoryCapacity'] ?? $vmHost['TotalPhysicalMemory'] ?? 0);
        $ramFree = (int) ($vmHost['ramFree'] ?? $data['ramFree'] ?? $vmHost['RamFree'] ?? $data['RamFree'] ?? $vmHost['AvailableMemory'] ?? $vmHost['FreePhysicalMemory'] ?? 0);
        $osBuild = (string) ($vmHost['osBuild'] ?? $data['osBuild'] ?? $vmHost['OsBuild'] ?? $data['OsBuild'] ?? $vmHost['BuildNumber'] ?? '');
        $uptime = $vmHost['uptime'] ?? $data['uptime'] ?? $vmHost['Uptime'] ?? $data['Uptime'] ?? null;
        $bootTime = $vmHost['bootTime'] ?? $data['bootTime'] ?? $vmHost['BootTime'] ?? $data['BootTime'] ?? $vmHost['LastBootUpTime'] ?? null;
        $cpuLoadPercent = $vmHost['cpuLoadPercent'] ?? $data['cpuLoadPercent'] ?? $vmHost['CpuLoadPercent'] ?? $data['CpuLoadPercent'] ?? $vmHost['LoadPercentage'] ?? null;
        $storageFree = $data['storageFree'] ?? $data['StorageFree'] ?? $vmHost['storageFree'] ?? $vmHost['StorageFree'] ?? null;

        $volumes = $data['volumes'] ?? $data['Volumes'] ?? $vmHost['volumes'] ?? $vmHost['Volumes'] ?? [];
        if (! is_array($volumes)) {
            $volumes = [];
        }
        $volumeList = [];
        foreach ($volumes as $vol) {
            if (! is_array($vol)) {
                continue;
            }
            $volumeList[] = [
                'name' => (string) ($vol['name'] ?? $vol['Name'] ?? ''),
                'total' => (int) ($vol['total'] ?? $vol['Total'] ?? 0),
                'used' => (int) ($vol['used'] ?? $vol['Used'] ?? 0),
                'free' => (int) ($vol['free'] ?? $vol['Free'] ?? 0),
            ];
        }

        $storageTotal = $data['storageTotal'] ?? $data['StorageTotal'] ?? $vmHost['storageTotal'] ?? $vmHost['StorageTotal'] ?? null;
        $storageUsed = $data['storageUsed'] ?? $data['StorageUsed'] ?? $vmHost['storageUsed'] ?? $vmHost['StorageUsed'] ?? null;
        if (($storageTotal === null || $storageUsed === null || $storageFree === null) && $volumeList !== []) {
            $sumTotal = 0;
            $sumUsed = 0;
            $sumFree = 0;
            foreach ($volumeList as $vol) {
                $sumTotal += $vol['total'];
                $sumUsed += $vol['used'];
                $sumFree += $vol['free'];
            }
            if ($storageTotal === null) {
                $storageTotal = $sumTotal;
            }
            if ($storageUsed === null) {
                $storageUsed = $sumUsed;
            }
            if ($storageFree === null) {
                $storageFree = $sumFree;
            }
        }

        $switchList = [];
        $switchDetails = [];
        foreach ($switches as $sw) {
            if (is_string($sw)) {
                $name = trim($sw);
                if ($name === '') {
                    continue;
                }
                $switchList[] = $name;
                $switchDetails[] = ['name' => $name, 'type' => null];
            } elseif (is_array($sw)) {
                $name = trim((string) ($sw['Name'] ?? $sw['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $switchList[] = $name;
                $type = $sw['SwitchType'] ?? $sw['switchType'] ?? $sw['type'] ?? $sw['Type'] ?? null;
                $switchDetails[] = ['name' => $name, 'type' => is_string($type) && trim($type) !== '' ? trim($type) : null];
            }
        }

        return new ServerInfoDTO(
            hostname: (string) ($vmHost['hostname'] ?? $vmHost['Hostname'] ?? $vmHost['ComputerName'] ?? $data['hostname'] ?? $data['Hostname'] ?? $host),
            version: $hypervVersion !== '' ? $hypervVersion : $hostOS,
            ipAddress: (string) ($this->server->ip_address ?? ''),
            totalAccounts: $total,
            latencyMs: 0,
            meta: [
                'hostOS' => $hostOS,
                'hypervVersion' => $hypervVersion,
                'osBuild' => $osBuild,
                'logicalCpu' => $logicalCpu,
                'cpuLoadPercent' => $cpuLoadPercent !== null ? (int) $cpuLoadPercent : null,
                'ramTotal' => $ramTotal,
                'ramFree' => $ramFree,
                'uptime' => $uptime,
                'bootTime' => $bootTime,
                'vmCounts' => ['running' => $running, 'stopped' => $stopped, 'saved' => $saved, 'total' => $total],
                'switches' => $switchList,
                'switchDetails' => $switchDetails,
                'storageFree' => $storageFree,
                'storageTotal' => $storageTotal,
                'storageUsed' => $storageUsed,
                'volumes' => $volumeList,
            ],
        );
    }

    private function host(): string
    {
        $raw = trim((string) ($this->server->api_url ?: $this->server->ip_address));

        // api_url may be full URL; extract host part
        if (str_contains($raw, '://')) {
            $parts = parse_url($raw);

            return (string) ($parts['host'] ?? $raw);
        }

        // Strip port if present
        if (str_contains($raw, ':')) {
            return (string) explode(':', $raw)[0];
        }

        return $raw;
    }

    private function port(): int
    {
        $meta = $this->server->connection_meta;
        if (is_array($meta)) {
            if (array_key_exists('port', $meta) && $meta['port'] !== '' && $meta['port'] !== null) {
                return (int) $meta['port'];
            }
            if (array_key_exists('Port', $meta) && $meta['Port'] !== '' && $meta['Port'] !== null) {
                return (int) $meta['Port'];
            }
        }

        $raw = trim((string) $this->server->api_url);

        if ($raw !== '' && str_contains($raw, '://')) {
            $parts = parse_url($raw);
            if (isset($parts['port'])) {
                return (int) $parts['port'];
            }
        }

        // Check if api_url contains port (host:port without scheme)
        if ($raw !== '' && preg_match('/:\d+$/', $raw) === 1) {
            return (int) substr(strrchr($raw, ':'), 1);
        }

        return 5985;
    }

    private function useSsl(int $port): bool
    {
        $meta = $this->server->connection_meta;
        if (is_array($meta)) {
            if (array_key_exists('use_ssl', $meta) && $meta['use_ssl'] !== '' && $meta['use_ssl'] !== null) {
                return (bool) filter_var($meta['use_ssl'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $meta['use_ssl'];
            }
            if (array_key_exists('useSsl', $meta) && $meta['useSsl'] !== '' && $meta['useSsl'] !== null) {
                return (bool) filter_var($meta['useSsl'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $meta['useSsl'];
            }
        }

        // api_url scheme fallback; port 5986 implies SSL only when scheme absent
        $raw = trim((string) $this->server->api_url);
        if (str_contains($raw, 'https://')) {
            return true;
        }
        if (str_contains($raw, 'http://')) {
            return false;
        }

        return $port === 5986;
    }

    private function verifyTls(): bool
    {
        $meta = $this->server->connection_meta;
        if (is_array($meta)) {
            if (array_key_exists('verify_tls', $meta)) {
                return (bool) $meta['verify_tls'];
            }
            if (array_key_exists('verifyTls', $meta)) {
                return (bool) $meta['verifyTls'];
            }
        }

        return true;
    }

    private function password(): string
    {
        return (string) ($this->server->api_password_encrypted ?? '');
    }

    private function testWsManEnvelope(string $host): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<Envelope xmlns="http://www.w3.org/2003/05/soap-envelope">
  <Header><Action xmlns="http://schemas.dmtf.org/wbem/wsman/1/wsman.xsd">http://schemas.dmtf.org/wbem/wsman/1/wsman/Identify</Action></Header>
  <Body><Identify xmlns="http://schemas.dmtf.org/wbem/wsman/identity/1/wsmanidentity.xsd"/></Body>
</Envelope>
XML;
    }

    private function invokeEnvelope(string $command): string
    {
        $escaped = htmlspecialchars($command, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<Envelope xmlns="http://www.w3.org/2003/05/soap-envelope">
  <Header><Action xmlns="http://schemas.dmtf.org/wbem/wsman/1/wsman.xsd">http://schemas.microsoft.com/wbem/wsman/1/windows/shell/Command</Action></Header>
  <Body><CommandLine xmlns="http://schemas.microsoft.com/wbem/wsman/1/windows/shell"><Command>{$escaped}</Command></CommandLine></Body>
</Envelope>
XML;
    }

    private function looksLikeWsManSuccess(string $body): bool
    {
        if (trim($body) === '') {
            return true;
        }

        // SOAP fault signals failure
        if (stripos($body, '<Fault') !== false || stripos($body, 'soap:Fault') !== false) {
            return false;
        }

        // JSON fake with error key
        $decoded = json_decode($body, true);
        if (is_array($decoded) && ! empty($decoded['error'])) {
            return false;
        }

        return true;
    }

    private function flattenError(mixed $error): string
    {
        if (is_string($error)) {
            return trim($error) !== '' ? trim($error) : 'no reason given';
        }

        if (is_array($error)) {
            $parts = [];
            array_walk_recursive($error, static function ($value) use (&$parts): void {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $parts[] = trim((string) $value);
                }
            });
            if ($parts !== []) {
                return implode('; ', array_unique($parts));
            }
        }

        return 'no reason given';
    }

    private function isSoapFault(string $body): bool
    {
        return stripos($body, 'MessageInformationHeaderRequired') !== false
            || stripos($body, '<Fault') !== false
            || stripos($body, 'soap:Fault') !== false
            || stripos($body, 'WSManFault') !== false;
    }

    /**
     * Human-readable text for a host protocol rejection. Tags stripped,
     * truncated, password never echoed, no connectivity boilerplate.
     */
    private function protocolFaultMessage(string $body, string $host, int $port): string
    {
        $password = $this->password();

        if ($password !== '' && str_contains($body, $password)) {
            $body = str_replace($password, '***', $body);
        }

        $text = html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            $text = 'no reason given';
        } elseif (strlen($text) > 500) {
            $text = substr($text, 0, 500) . '…';
        }

        return sprintf(
            'Hyper-V host %s:%d rejected the WinRM request: %s (protocol rejection, not credentials — on Windows app hosts use the PowerShell-remoting path).',
            $host,
            $port,
            $text,
        );
    }

    private function httpErrorMessage(int $status, string $host, int $port, bool $useSsl): string
    {
        $base = sprintf('Hyper-V host %s:%d returned HTTP %d.', $host, $port, $status);

        if ($status === 401 || $status === 403) {
            return $base . ' Check username (use DOMAIN\\user for domain-joined hosts) and password. ' . $this->trustedHostsHint($host);
        }

        if ($status === 0 || $status >= 500) {
            return $base . ' ' . $this->trustedHostsHint($host);
        }

        return $base;
    }

    private function trustedHostsHint(string $host): string
    {
        return sprintf(
            'If this is a workgroup (non-domain) host, run on the client: winrm quickconfig; winrm set winrm/config/client @{TrustedHosts="%s"}; and ensure the host firewall allows %s. For domain-joined hosts use Kerberos/Negotiate via domain\\user. See Microsoft docs: Remotely Manage Hyper-V Hosts.',
            $host,
            'ports 5985 (HTTP) / 5986 (HTTPS)'
        );
    }

    private function sanitizeMessage(string $message, string $host, int $port, bool $useSsl): string
    {
        $password = $this->password();

        // Never echo password
        if ($password !== '' && str_contains($message, $password)) {
            $message = str_replace($password, '***', $message);
        }

        // Protocol rejections carry their own reason — no connectivity hint.
        if ($this->isSoapFault($message)) {
            $text = html_entity_decode(strip_tags($message), ENT_QUOTES, 'UTF-8');
            $text = trim((string) preg_replace('/\s+/', ' ', $text));

            return $text !== '' ? $text : 'Hyper-V host rejected the WinRM request.';
        }

        $lower = strtolower($message);

        if (str_contains($lower, 'could not resolve host') || str_contains($lower, 'name or service not known') || str_contains($lower, 'getaddrinfo')) {
            return sprintf('Could not resolve Hyper-V host %s. Check host/hostname.', $host);
        }

        if (str_contains($lower, 'connection refused') || str_contains($lower, 'curl error 7') || str_contains($lower, 'failed to connect') || str_contains($lower, 'timed out') || str_contains($lower, 'timeout') || str_contains($lower, 'cURL error 28')) {
            return sprintf(
                'Could not reach Hyper-V host %s:%d (%s). Check that WinRM is enabled (winrm quickconfig), the firewall allows %s, and the host is reachable. %s',
                $host,
                $port,
                $useSsl ? 'HTTPS' : 'HTTP',
                'ports 5985/5986',
                $this->trustedHostsHint($host)
            );
        }

        if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'certificate verify failed')) {
            return 'TLS verification failed for Hyper-V host ' . $host . '. If using a self-signed certificate, disable verify_tls or install the CA. ' . $this->trustedHostsHint($host);
        }

        if (str_contains($lower, '401') || str_contains($lower, 'unauthorized') || str_contains($lower, 'access is denied') || str_contains($lower, 'logon failure')) {
            return sprintf('Authentication failed for Hyper-V host %s. Check username (domain\\user) and password. %s', $host, $this->trustedHostsHint($host));
        }

        if (str_contains($lower, 'trustedhosts') || str_contains($lower, 'winrm') || str_contains($lower, 'credssp') || str_contains($lower, 'negotiate')) {
            return $message . ' ' . $this->trustedHostsHint($host);
        }

        // Fallback: flatten + append hint
        $flat = $this->flattenError($message);

        return $flat . ' ' . $this->trustedHostsHint($host);
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
