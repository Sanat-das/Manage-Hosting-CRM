<?php

declare(strict_types=1);

namespace Modules\Virtualizor;

use App\Contracts\Module\AbstractPanelModule;
use App\Contracts\Module\PanelException;
use App\Contracts\Module\PanelProvisionRequest;
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
final class Virtualizor extends AbstractPanelModule
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
                ['key' => 'contact_email', 'label' => 'Contact email override', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'verify_tls', 'label' => 'Verify the Virtualizor TLS certificate', 'type' => 'checkbox', 'default' => true],
            ],
        ];
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
}
