<?php

declare(strict_types=1);

namespace App\Modules\Virtualizor;

use App\Contracts\Integrations\AbstractPanelModule;
use App\Contracts\Integrations\PanelException;
use App\Contracts\Integrations\PanelProvisionRequest;
use App\Contracts\Integrations\ProvisioningResult;
use App\Contracts\Integrations\ServerConnectionResult;
use App\Contracts\Integrations\ServerInfoDTO;
use App\Contracts\Integrations\TestableServerModule;
use App\Models\PanelAccount;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Modules\Virtualizor\Services\VirtualizorClient;

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
                meta: self::capMeta(self::successProvenance('listvs')),
            );
        } catch (PanelException $e) {
            $latency = (int) (microtime(true) * 1000) - $start;

            return ServerConnectionResult::fail($e->getMessage(), $latency, self::errorMeta($e->getMessage()));
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
                meta: self::successProvenance(
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
                meta: self::errorMeta($e->getMessage()),
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
     * Every OS template a VPS can be built from, for the server curation UI.
     * Empty on any failure so a panel hiccup cannot break the page.
     *
     * @return list<array{osid: string, name: string, type: string}>
     */
    public function discoverOsTemplates(Server $server): array
    {
        try {
            return (new VirtualizorClient($server, verifyTls: true))->listOsTemplates();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Is the VPS behind this panel account still on its server?
     *
     * Same hook contract as HyperV/Proxmox: `true`/`false` are verified
     * answers, `null` means the panel could not be reached and callers must
     * treat it as unknown (fail safe).
     *
     * @return array{exists: bool|null, status?: string, error?: string}
     */
    public function recordedVmState(PanelAccount $account): array
    {
        $id = trim((string) ($account->external_id ?? ''));

        if ($id === '' || (int) $id <= 0) {
            return ['exists' => false];
        }

        $server = $account->server_id !== null ? Server::find($account->server_id) : null;

        if ($server === null) {
            return ['exists' => null, 'error' => 'The server this VPS was built on no longer exists.'];
        }

        try {
            $state = (new VirtualizorClient($server, verifyTls: true))->vpsState((int) $id);
        } catch (\Throwable $e) {
            return ['exists' => null, 'error' => $e->getMessage()];
        }

        if ($state === null) {
            return ['exists' => false];
        }

        return ['exists' => true, 'status' => $state];
    }

    /**
     * Restart the VPS. NOT part of ProvisioningModule — called directly by
     * HostingController::moduleAction() / the client vmPower endpoint after
     * confirmation, mirroring HyperV::restart().
     */
    public function restart(ServiceInstance $service, array $config): ProvisioningResult
    {
        $account = $this->accountFor($service);

        if ($account === null) {
            return ProvisioningResult::fail('No Virtualizor VPS is recorded for this service.');
        }

        $server = $service->server;

        if ($server === null || ! $this->serverIsConfigured($server)) {
            return ProvisioningResult::fail(sprintf(
                'Server "%s" is not configured for virtualizor (%s).',
                $server?->name ?? $service->server_id,
                $this->credentialHint(),
            ));
        }

        try {
            $id = (int) $this->vpsId($account);
            $client = $this->client($server, $config);

            // A restart signal on a stopped VPS would start it — refused
            // explicitly, matching the UI's "only a running VM is rebooted".
            if ($client->vpsState($id) !== 'running') {
                return ProvisioningResult::fail(sprintf(
                    'Virtualizor VPS %d is not running — restart is refused. Start it first.',
                    $id,
                ));
            }

            $client->restartVps($id);
        } catch (PanelException $e) {
            return ProvisioningResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            return ProvisioningResult::fail('Virtualizor restart failed unexpectedly: '.$e->getMessage());
        }

        return ProvisioningResult::ok("Virtualizor VPS {$id} restarted", [
            'username' => $account->username,
            'external_id' => (string) $id,
            'state' => 'Running',
        ]);
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
