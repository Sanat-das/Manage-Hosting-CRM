<?php

declare(strict_types=1);

namespace Modules\Virtualizor;

use App\Contracts\Module\AbstractPanelModule;
use App\Contracts\Module\PanelException;
use App\Contracts\Module\PanelProvisionRequest;
use App\Contracts\Module\ServerConnectionResult;
use App\Contracts\Module\ServerInfoDTO;
use App\Contracts\Module\TestableServerModule;
use App\Models\PanelAccount;
use App\Models\Server;
use Modules\Virtualizor\Services\VirtualizorClient;

/**
 * Virtualizor VPS provisioning module.
 *
 * The odd one out: this creates a virtual machine, not a hosting account. That
 * changes two things about the shared lifecycle —
 *
 *  - a domain is optional (a VPS has a hostname; `requiresDomain()` is false),
 *    so an order for a VPS with no domain still provisions;
 *  - the lifecycle actions address the VPS by its numeric `vpsid`, which only
 *    exists after creation. It is stored as `external_id` and every later call
 *    fails loudly if it is missing rather than acting on the wrong machine.
 *
 * The plan (`plid`) and OS template (`osid`) are per-product config: they are
 * Virtualizor's own numeric ids, which vary per installation.
 */
final class Virtualizor extends AbstractPanelModule implements TestableServerModule
{
    public function configSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'plan', 'label' => 'Plan ID (plid)', 'type' => 'text', 'required' => true, 'default' => ''],
                ['key' => 'osid', 'label' => 'OS template ID (osid)', 'type' => 'text', 'required' => true, 'default' => ''],
                ['key' => 'virt', 'label' => 'Virtualization type', 'type' => 'select', 'default' => 'kvm', 'options' => [
                    'kvm' => 'KVM', 'openvz' => 'OpenVZ', 'lxc' => 'LXC', 'proxk' => 'Proxmox KVM', 'proxl' => 'Proxmox LXC',
                ]],
                ['key' => 'cpu', 'label' => 'vCPUs', 'type' => 'number', 'required' => false, 'default' => 2],
                ['key' => 'ram', 'label' => 'RAM (MB)', 'type' => 'number', 'required' => false, 'default' => 2048],
                ['key' => 'disk', 'label' => 'Disk (GB)', 'type' => 'number', 'required' => false, 'default' => 50],
                ['key' => 'contact_email', 'label' => 'Contact email override', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'verify_tls', 'label' => 'Verify the Virtualizor TLS certificate', 'type' => 'checkbox', 'default' => true],
            ],
        ];
    }

    public function serverConfigSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'api_url', 'label' => 'Virtualizor URL (e.g. https://vps.example.net:4085)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'api_username', 'label' => 'Virtualizor API key', 'type' => 'text', 'required' => true],
                ['key' => 'api_key', 'label' => 'Virtualizor API pass', 'type' => 'password', 'required' => true, 'encrypted' => true],
                ['key' => 'verify_tls', 'label' => 'Verify Virtualizor TLS certificate', 'type' => 'checkbox', 'default' => true],
            ],
        ];
    }

    public function testConnection(Server $server): ServerConnectionResult
    {
        $start = (int) (microtime(true) * 1000);

        try {
            $data = $this->serverClient($server)->call('listvs');
            $latency = (int) (microtime(true) * 1000) - $start;

            // Todo 13: error-only raw — the VS list never persists on
            // success; provenance does.
            return ServerConnectionResult::ok(
                message: 'Connected to Virtualizor',
                latencyMs: $latency,
                meta: static::capMeta(static::successProvenance('listvs')),
            );
        } catch (PanelException $e) {
            $latency = (int) (microtime(true) * 1000) - $start;

            return ServerConnectionResult::fail($e->getMessage(), $latency, static::errorMeta($e->getMessage()));
        }
    }

    public function getServerInfo(Server $server): ServerInfoDTO
    {
        $start = (int) (microtime(true) * 1000);

        try {
            $data = $this->serverClient($server)->call('listvs');
            $latency = (int) (microtime(true) * 1000) - $start;

            // listvs returns vs array or vs=>[...]; count them.
            $vs = $data['vs'] ?? $data['data']['vs'] ?? $data;
            $totalAccounts = is_array($vs) ? count(array_filter($vs, fn ($v) => is_array($v) || is_numeric($v))) : 0;
            // Fallback: if vs not found, count top-level numeric keys
            if ($totalAccounts === 0 && is_array($data)) {
                // Virtualizor sometimes returns {vs: {1: {...}, 2: {...}}}
                $maybe = $data['vs'] ?? null;
                if (is_array($maybe)) {
                    $totalAccounts = count($maybe);
                }
            }

            return new ServerInfoDTO(
                hostname: (string) ($server->api_url ?: $server->ip_address),
                version: (string) ($data['version'] ?? ''),
                ipAddress: (string) $server->ip_address,
                totalAccounts: $totalAccounts,
                latencyMs: $latency,
                // Todo 13: error-only raw — totals ride the DTO top level;
                // the VS list never persists on success.
                meta: static::successProvenance(
                    'listvs',
                    isset($data['version']) && is_scalar($data['version']) && trim((string) $data['version']) !== '' ? (string) $data['version'] : null,
                    trim((string) ($server->api_url ?: $server->ip_address)) !== '' ? trim((string) ($server->api_url ?: $server->ip_address)) : null,
                ),
            );
        } catch (PanelException $e) {
            $latency = (int) (microtime(true) * 1000) - $start;

            return new ServerInfoDTO(
                hostname: (string) ($server->api_url ?: $server->ip_address),
                version: '',
                ipAddress: (string) $server->ip_address,
                totalAccounts: 0,
                latencyMs: $latency,
                meta: static::errorMeta($e->getMessage()),
            );
        }
    }

    protected function panel(): string
    {
        return 'virtualizor';
    }

    /** A VPS has a hostname, not necessarily a domain. */
    protected function requiresDomain(): bool
    {
        return false;
    }

    protected function serverIsConfigured(?Server $server): bool
    {
        return VirtualizorClient::isConfigured($server);
    }

    protected function credentialHint(): string
    {
        return 'set api_username to the Virtualizor API key and api_key to the API pass';
    }

    protected function createRemote(PanelProvisionRequest $request): array
    {
        if ($request->plan === '') {
            throw new PanelException('Virtualizor needs a Plan ID (plid) - set one on the product\'s module config.');
        }

        $osid = $request->config('osid');

        if ($osid === '') {
            throw new PanelException('Virtualizor needs an OS template ID (osid) - set one on the product\'s module config.');
        }

        // Canonical cpu/ram/disk from merged provisioning config (snapshot cpu/ram/disk
        // merged under module config by ProvisioningDispatcher). Keep legacy fallbacks
        // mirroring Hyper-V (vcpus / memory / disk_gb) and defaults 2/2048/50.
        $cpuRaw = $request->config('cpu') !== '' ? $request->config('cpu') : $request->config('vcpus', '2');
        $ramRaw = $request->config('ram') !== '' ? $request->config('ram') : $request->config('memory', '2048');
        $diskRaw = $request->config('disk') !== '' ? $request->config('disk') : $request->config('disk_gb', '50');
        $cpu = max(1, (int) $cpuRaw);
        $ramMb = max(128, (int) $ramRaw);
        $diskGb = max(1, (int) $diskRaw);

        $result = $this->client($request->server, $request->config)->call('addvs', [], [
            'virt' => $request->config('virt', 'kvm'),
            'user_email' => $request->contactEmail,
            'user_pass' => $request->password,
            'hostname' => $request->domain !== '' ? $request->domain : $request->username.'.vps',
            'rootpass' => $request->password,
            'plid' => $request->plan,
            'osid' => $osid,
            'serid' => 0,
            'addvps' => 1,
            // Provisioning overrides (plan still required — these tune the VPS when the provider honours them).
            'cpu' => $cpu,
            'ram' => $ramMb,
            'disk' => $diskGb,
            // Virtualizor-idiomatic aliases for the same values.
            'cores' => $cpu,
            'space' => $diskGb,
        ]);

        $vpsId = $result['vpsid'] ?? ($result['done']['vpsid'] ?? null);

        if ($vpsId === null) {
            // Without the id nothing later can address this machine, so treat
            // it as a failure rather than record an unmanageable VPS.
            throw new PanelException('Virtualizor created the VPS but returned no vpsid.');
        }

        return array_filter([
            'external_id' => (string) $vpsId,
            'ip' => $result['ips'][0] ?? ($result['done']['ips'][0] ?? null),
            'cpu' => $cpu,
            'ram' => $ramMb,
            'ramMb' => $ramMb,
            'disk' => $diskGb,
            'diskGb' => $diskGb,
        ], static fn ($v) => $v !== null);
    }

    protected function suspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        $this->client($server, $config)->call('vs', ['suspend' => $this->vpsId($account)]);
    }

    protected function unsuspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        $this->client($server, $config)->call('vs', ['unsuspend' => $this->vpsId($account)]);
    }

    protected function terminateRemote(PanelAccount $account, Server $server, array $config): void
    {
        $this->client($server, $config)->call('vs', ['delete' => $this->vpsId($account)]);
    }

    /**
     * @throws PanelException when the record has no VPS id to act on
     */
    private function vpsId(PanelAccount $account): string
    {
        $id = trim((string) $account->external_id);

        if ($id === '') {
            throw new PanelException('This service has no Virtualizor vpsid recorded - it cannot be managed remotely.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function client(Server $server, array $config): VirtualizorClient
    {
        return new VirtualizorClient(
            $server,
            verifyTls: filter_var($config['verify_tls'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }

    private function serverClient(Server $server): VirtualizorClient
    {
        return new VirtualizorClient($server, verifyTls: true);
    }
}
