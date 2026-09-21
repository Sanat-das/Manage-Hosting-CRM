<?php

declare(strict_types=1);

namespace App\Modules\HyperV;

use App\Contracts\Integrations\AbstractComputeModule;
use App\Contracts\Integrations\PanelException;
use App\Contracts\Integrations\PanelProvisionRequest;
use App\Contracts\Integrations\ProvisioningResult;
use App\Contracts\Integrations\ServerConnectionResult;
use App\Contracts\Integrations\ServerInfoDTO;
use App\Models\PanelAccount;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Modules\HyperV\Services\HyperVClient;

final class HyperV extends AbstractComputeModule
{
    public function configSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'plan', 'label' => 'Plan / hardware profile', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'vm', 'label' => 'VM name template', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'cpu', 'label' => 'vCPUs', 'type' => 'number', 'required' => false, 'default' => 2],
                ['key' => 'ram', 'label' => 'RAM (MB)', 'type' => 'number', 'required' => false, 'default' => 2048],
                ['key' => 'disk', 'label' => 'Disk (GB)', 'type' => 'number', 'required' => false, 'default' => 50],
                ['key' => 'switch', 'label' => 'Virtual switch', 'type' => 'text', 'required' => false, 'default' => 'Default Switch'],
                ['key' => 'generation', 'label' => 'VM generation (1 or 2)', 'type' => 'select', 'required' => false, 'default' => 2, 'options' => ['1' => 'Generation 1', '2' => 'Generation 2']],
                ['key' => 'iso', 'label' => 'ISO path (optional)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'delete_vhd_on_terminate', 'label' => 'Delete VHD on Delete (unchecked keeps the disk)', 'type' => 'checkbox', 'required' => false, 'default' => true],
            ],
        ];
    }

    public function serverConfigSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'host', 'label' => 'Host / hostname', 'type' => 'text', 'required' => true, 'default' => ''],
                ['key' => 'port', 'label' => 'WinRM port', 'type' => 'select', 'required' => true, 'default' => 5985, 'options' => ['5985' => '5985 (HTTP)', '5986' => '5986 (HTTPS)']],
                ['key' => 'username', 'label' => 'Username (domain\\user or user)', 'type' => 'text', 'required' => true, 'default' => ''],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'encrypted' => true, 'default' => ''],
                ['key' => 'use_ssl', 'label' => 'Use SSL (HTTPS)', 'type' => 'checkbox', 'required' => false, 'default' => false],
                ['key' => 'verify_tls', 'label' => 'Verify TLS certificate', 'type' => 'checkbox', 'required' => false, 'default' => true],
            ],
        ];
    }

    protected function panel(): string
    {
        return 'hyperv';
    }

    protected function serverIsConfigured(?Server $server): bool
    {
        return HyperVClient::isConfigured($server);
    }

    protected function credentialHint(): string
    {
        return 'set host, port, username and password (api_password_encrypted) on the server';
    }

    public function testConnection(Server $server): ServerConnectionResult
    {
        $client = new HyperVClient($server);

        return $client->testConnection();
    }

    public function getServerInfo(Server $server): ServerInfoDTO
    {
        $client = new HyperVClient($server);

        return $client->getServerInfo();
    }

    /**
     * Short-TTL server info for the admin server-show page. Single cache
     * home is HyperVClient::cachedServerInfo() (60s, keyed by server id).
     */
    public function getCachedServerInfo(Server $server, int $ttlSeconds = 60): ServerInfoDTO
    {
        return (new HyperVClient($server))->cachedServerInfo($ttlSeconds);
    }

    /**
     * Live per-VM inventory for the admin server-show VM table.
     *
     * @return list<array<string,mixed>>
     */
    public function listVms(Server $server): array
    {
        return (new HyperVClient($server))->listVms();
    }

    protected function createRemote(PanelProvisionRequest $request): array
    {
        // Build VM on the Hyper-V host via WinRM (New-VM). Production rule:
        // fail loud — a host error is NEVER converted into a logical record.
        //
        // The VM is named after the product's hostname (hosting account
        // host_name) so the two agree; the derived panel username is only the
        // legacy fallback when no hosting account exists yet (order flow
        // provisions before provisionFromOrder creates the row).
        $client = new HyperVClient($request->server);

        $cpu = (int) ($request->config['cpu'] ?? $request->config['vcpus'] ?? 2);
        $ramMb = (int) ($request->config['ram'] ?? $request->config['memory'] ?? 2048);
        $diskGb = (int) ($request->config['disk'] ?? $request->config['disk_gb'] ?? 50);
        $switch = trim((string) ($request->config['switch'] ?? $request->config['vswitch'] ?? ''));
        if ($switch === '') {
            $switch = 'Default Switch';
        }
        $generation = (int) ($request->config['generation'] ?? 2);
        $vmName = trim((string) ($request->config['host_name'] ?? ''));

        if ($vmName === '') {
            $vmName = $this->hostNameForService($request->service);
        }

        if ($vmName === '') {
            $vmName = $request->username;
        }

        $result = $client->createVm($vmName, $cpu, $ramMb, $diskGb, $switch, $generation);

        if (isset($result['error'])) {
            throw new PanelException('Hyper-V VM creation failed: '.$result['error']);
        }

        return $result;
    }

    /**
     * Graceful reboot. NOT part of ProvisioningModule — called directly by
     * HostingController::moduleAction() after a typed confirmation. No local
     * status change: a reboot leaves billing state untouched.
     */
    public function restart(ServiceInstance $service, array $config): ProvisioningResult
    {
        $account = $this->accountFor($service);

        if ($account === null) {
            return ProvisioningResult::fail('No Hyper-V VM is recorded for this service.');
        }

        if ($service->server_id === null || ! $this->serverIsConfigured($service->server)) {
            return ProvisioningResult::fail(sprintf(
                'Server "%s" is not configured for hyperv (%s).',
                $service->server?->name ?? $service->server_id,
                $this->credentialHint(),
            ));
        }

        try {
            $vmName = $this->vmNameFor($account);
            $result = (new HyperVClient($service->server))->restartVm($vmName);
        } catch (PanelException $e) {
            return ProvisioningResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            return ProvisioningResult::fail('Hyperv restart failed unexpectedly: '.$e->getMessage());
        }

        if (isset($result['error'])) {
            return ProvisioningResult::fail('Hyper-V restart failed: '.$result['error']);
        }

        return ProvisioningResult::ok("Hyper-V VM '{$vmName}' restarted", [
            'username' => $account->username,
            'external_id' => $account->external_id ?? $account->username,
            'state' => $result['state'] ?? null,
        ]);
    }

    protected function suspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        // Billing suspend == graceful Stop-VM (integration-services shutdown).
        // Never -Force / -TurnOff from the panel.
        $vmName = $this->vmNameFor($account);
        $result = (new HyperVClient($server))->stopVm($vmName);

        if (isset($result['error'])) {
            throw new PanelException('Hyper-V stop failed: '.$result['error']);
        }
    }

    protected function unsuspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        // Start-VM, idempotent when already Running.
        $vmName = $this->vmNameFor($account);
        $result = (new HyperVClient($server))->startVm($vmName);

        if (isset($result['error'])) {
            throw new PanelException('Hyper-V start failed: '.$result['error']);
        }
    }

    protected function terminateRemote(PanelAccount $account, Server $server, array $config): void
    {
        // Remove-VM -Force + optional recorded-VHD delete (default ON, per
        // product opt-out). Refuses while Running — operator stops first.
        $vmName = $this->vmNameFor($account);
        $deleteVhd = filter_var($config['delete_vhd_on_terminate'] ?? true, FILTER_VALIDATE_BOOL);
        $vhdPath = $this->recordedVhdPath($account);

        $result = (new HyperVClient($server))->removeVm($vmName, $vhdPath, $deleteVhd);

        if (isset($result['error'])) {
            throw new PanelException('Hyper-V delete failed: '.$result['error']);
        }
    }

    /**
     * @throws PanelException when the record has no host-manageable VM name
     */
    private function vmNameFor(PanelAccount $account): string
    {
        $meta = is_array($account->meta) ? $account->meta : [];
        $inner = is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
        $name = trim((string) ($inner['vmName'] ?? $inner['name'] ?? $meta['vmName'] ?? $meta['name'] ?? $account->username));

        if ($name === '' || str_starts_with($account->external_id ?? '', 'hv-')) {
            throw new PanelException('This service has no host-manageable Hyper-V VM recorded (logical record) — it cannot be managed remotely.');
        }

        return substr($name, 0, 64);
    }

    private function recordedVhdPath(PanelAccount $account): ?string
    {
        $meta = is_array($account->meta) ? $account->meta : [];
        $inner = is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
        $path = $inner['vhdPath'] ?? $meta['vhdPath'] ?? null;

        return is_string($path) && trim($path) !== '' ? trim($path) : null;
    }

    /**
     * The product hostname for a service, or '' when none is recorded yet.
     *
     * Looks up the hosting account by order (order flow) or by the HOST-{id}
     * mirror tag (manual flow). Blank (never null) so the caller falls back
     * to the derived username; the VM is never created nameless.
     */
    private function hostNameForService(ServiceInstance $service): string
    {
        try {
            if ($service->order_id !== null) {
                $name = \App\Models\HostingAccount::where('order_id', $service->order_id)->value('host_name');

                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }

            if (is_string($service->service_tag) && preg_match('/^HOST-(\d+)$/', $service->service_tag, $m) === 1) {
                $account = \App\Models\HostingAccount::find((int) $m[1]);

                if ($account !== null && trim((string) $account->host_name) !== '') {
                    return trim((string) $account->host_name);
                }
            }
        } catch (\Throwable) {
            // Lookup must never break provisioning — fallback covers it.
        }

        return '';
    }
}
