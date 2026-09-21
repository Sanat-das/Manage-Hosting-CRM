<?php

declare(strict_types=1);

namespace App\Modules\Proxmox;

use App\Contracts\Integrations\AbstractComputeModule;
use App\Contracts\Integrations\PanelException;
use App\Contracts\Integrations\PanelProvisionRequest;
use App\Contracts\Integrations\ServerConnectionResult;
use App\Contracts\Integrations\ServerInfoDTO;
use App\Models\PanelAccount;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Contracts\Integrations\ProvisioningResult;

final class Proxmox extends AbstractComputeModule
{
    public function configSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'plan', 'label' => 'Plan', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'cpu', 'label' => 'vCPUs', 'type' => 'number', 'required' => false, 'default' => 2],
                ['key' => 'ram', 'label' => 'RAM (MB)', 'type' => 'number', 'required' => false, 'default' => 2048],
                ['key' => 'disk', 'label' => 'Disk (GB)', 'type' => 'number', 'required' => false, 'default' => 50],
            ],
        ];
    }

    public function serverConfigSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true, 'default' => ''],
                ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true, 'default' => ''],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'encrypted' => true, 'default' => ''],
            ],
        ];
    }

    protected function panel(): string
    {
        return 'proxmox';
    }

    protected function serverIsConfigured(?Server $server): bool
    {
        return $server !== null && trim((string) ($server->api_password_encrypted ?? '')) !== '';
    }

    public function testConnection(Server $server): ServerConnectionResult
    {
        return ServerConnectionResult::fail('Coming soon — Proxmox VE integration is not yet available.');
    }

    public function getServerInfo(Server $server): ServerInfoDTO
    {
        return new ServerInfoDTO(
            hostname: (string) ($server->api_url ?: $server->ip_address),
            version: '',
            ipAddress: (string) $server->ip_address,
            totalAccounts: 0,
            latencyMs: 0,
            meta: ['note' => 'Coming soon — Proxmox VE integration is not yet available.'],
        );
    }

    public function provision(ServiceInstance $service, array $config): ProvisioningResult
    {
        // Consume merged canonical cpu/ram/disk (snapshot merged under module config)
        // with Hyper-V-style legacy fallbacks so dispatcher snapshot keys are ready
        // the moment the driver goes live. Stub still returns fail.
        $cpu = (int) ($config['cpu'] ?? $config['vcpus'] ?? 2);
        $ramMb = (int) ($config['ram'] ?? $config['memory'] ?? 2048);
        $diskGb = (int) ($config['disk'] ?? $config['disk_gb'] ?? 50);
        unset($cpu, $ramMb, $diskGb);

        return ProvisioningResult::fail('Coming soon — Proxmox VE integration is not yet available.');
    }

    protected function createRemote(PanelProvisionRequest $request): array
    {
        // Canonical cpu/ram/disk via config helper with Hyper-V fallback style
        // (vcpus / memory / disk_gb) and defaults 2/2048/50. Kept in stub so merged
        // keys are consumable immediately when the live implementation lands.
        $cpuRaw = $request->config('cpu') !== '' ? $request->config('cpu') : $request->config('vcpus', '2');
        $ramRaw = $request->config('ram') !== '' ? $request->config('ram') : $request->config('memory', '2048');
        $diskRaw = $request->config('disk') !== '' ? $request->config('disk') : $request->config('disk_gb', '50');
        $cpu = max(1, (int) $cpuRaw);
        $ramMb = max(128, (int) $ramRaw);
        $diskGb = max(1, (int) $diskRaw);

        // Live driver would include these in the Proxmox VE API payload (e.g. cores,
        // memory, rootfs size) and return them in meta:
        //   ['cpu' => $cpu, 'ram' => $ramMb, 'ramMb' => $ramMb, 'disk' => $diskGb, 'diskGb' => $diskGb]
        // Stub keeps the reads above so snapshot merging is already covered.
        unset($cpu, $ramMb, $diskGb);

        throw new PanelException('Coming soon');
    }

    protected function suspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        throw new PanelException('Coming soon');
    }

    protected function unsuspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        throw new PanelException('Coming soon');
    }

    protected function terminateRemote(PanelAccount $account, Server $server, array $config): void
    {
        throw new PanelException('Coming soon');
    }
}
