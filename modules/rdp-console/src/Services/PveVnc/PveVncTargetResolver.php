<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Services\PveVnc;

use App\Contracts\Integrations\PanelException;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\ServiceInstance;
use App\Modules\Proxmox\Services\ProxmoxClient;
use App\Support\Logging\AppLog;
use Modules\RdpConsole\Exceptions\PveVncUnavailableException;
use Throwable;

/**
 * Resolves a Proxmox VNC console target strictly server-side: the account's
 * own proxmox PanelAccount (VMID), that VM's Server row (API host + token)
 * and, unless facts-only mode is requested, a one-shot vncproxy ticket from
 * PVE. Nothing here reads request input,
 * and a missing fact fails closed — it never falls back to another VM.
 *
 * Returns exactly the `pve` block the GuacamoleLiteDriver embeds in the
 * token for the sidecar: apiHost, apiPort, verifyTls, tokenId, tokenSecret,
 * node, vmid, vncPort and vncTicket.
 */
final class PveVncTargetResolver
{
    /**
     * @return array{apiHost: string, apiPort: int, verifyTls: bool, tokenId: string, tokenSecret: string, node: string, vmid: int, vncPort: int|null, vncTicket: string|null}
     *
     * @throws PveVncUnavailableException when any server-side fact is missing
     */
    public function resolve(HostingAccount $hostingAccount, bool $withProxy = true): array
    {
        $panelAccount = $this->panelAccountFor($hostingAccount);

        if ($panelAccount === null) {
            throw new PveVncUnavailableException('No Proxmox VE VM is recorded for this service.');
        }

        $vmid = (int) trim((string) ($panelAccount->external_id ?? ''));

        if ($vmid <= 0) {
            throw new PveVncUnavailableException('No Proxmox VE VMID is recorded for this service — provision it first.');
        }

        // The PanelAccount's server is the cluster endpoint that owns the VM;
        // the account's server is the fallback when the panel row predates it.
        $server = $panelAccount->server ?? $hostingAccount->server;

        if ($server === null || ! ProxmoxClient::isConfigured($server)) {
            throw new PveVncUnavailableException('The Proxmox VE server for this service is missing or not configured.');
        }

        $client = new ProxmoxClient($server);

        $node = $this->nodeFor($client, $panelAccount, $vmid);

        if ($node === '') {
            throw new PveVncUnavailableException('The Proxmox VE node for this VM could not be resolved.');
        }

        // Facts-only mode for the console page: the canvas mints its own
        // one-shot ticket on connect, so the page must not burn a PVE write
        // just to learn the VNC port.
        if (! $withProxy) {
            return [
                'apiHost' => $client->apiHost(),
                'apiPort' => $client->port(),
                'verifyTls' => $client->verifyTls(),
                'tokenId' => ProxmoxClient::tokenId($server),
                'tokenSecret' => ProxmoxClient::tokenSecret($server),
                'node' => $node,
                'vmid' => $vmid,
                'vncPort' => null,
                'vncTicket' => null,
            ];
        }

        try {
            $proxy = $client->createVncProxy($node, $vmid);
        } catch (PanelException $e) {
            throw new PveVncUnavailableException('The Proxmox VE console is not available right now.', 0, $e);
        }

        return [
            'apiHost' => $client->apiHost(),
            'apiPort' => $client->port(),
            'verifyTls' => $client->verifyTls(),
            'tokenId' => ProxmoxClient::tokenId($server),
            'tokenSecret' => ProxmoxClient::tokenSecret($server),
            'node' => $node,
            'vmid' => $vmid,
            'vncPort' => $proxy['port'],
            'vncTicket' => $proxy['ticket'],
        ];
    }

    /**
     * The account's proxmox PanelAccount, or null. Same lookup chain
     * VmConnectTargetResolver uses: order id first, then the HOST-{id}
     * service tag. Lookup only — never creates a ServiceInstance row.
     */
    private function panelAccountFor(HostingAccount $hostingAccount): ?PanelAccount
    {
        try {
            $service = null;

            if ($hostingAccount->order_id !== null) {
                $service = ServiceInstance::where('order_id', $hostingAccount->order_id)->first();
            }

            $service ??= ServiceInstance::where('service_tag', 'HOST-'.$hostingAccount->id)->first();

            return $service !== null
                ? PanelAccount::where('service_instance_id', $service->id)->where('panel', 'proxmox')->first()
                : null;
        } catch (Throwable $e) {
            // Un-migrated tables degrade to "no VM recorded", never a 500.
            AppLog::provisioning()->debug('PVE VNC panel account lookup failed — no VM resolved', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The node the VM lives on. Prefer the node recorded in the panel
     * account meta at build time (verified against PVE), then any node that
     * actually holds the VMID, then the recorded node unchecked, then the
     * cluster default. Mirrors Proxmox::nodeFor().
     */
    private function nodeFor(ProxmoxClient $client, PanelAccount $panelAccount, int $vmid): string
    {
        $recorded = $this->recordedNode($panelAccount);

        if ($recorded !== '') {
            try {
                if ($client->vmExists($recorded, $vmid)['exists'] === true) {
                    return $recorded;
                }
            } catch (PanelException) {
                // Fall through to a cluster-wide search.
            }
        }

        try {
            foreach ($client->cachedNodes() as $node) {
                try {
                    if ($client->vmExists($node, $vmid)['exists'] === true) {
                        return $node;
                    }
                } catch (PanelException) {
                    continue;
                }
            }
        } catch (PanelException) {
            // Node listing failed — fall through to the recorded/default node.
        }

        if ($recorded !== '') {
            return $recorded;
        }

        try {
            return $client->defaultNode();
        } catch (PanelException) {
            return '';
        }
    }

    /**
     * The node recorded in the panel account meta at build time. Mirrors
     * Proxmox::recordedNode(): the inner `meta.meta` mirror first, then the
     * top-level `meta` (provisioning writes `meta.node`, older rows may nest
     * it under `meta.meta`).
     */
    private function recordedNode(PanelAccount $panelAccount): string
    {
        $meta = is_array($panelAccount->meta) ? $panelAccount->meta : [];
        $inner = is_array($meta['meta'] ?? null) ? $meta['meta'] : [];

        $node = trim((string) ($inner['node'] ?? ''));

        if ($node !== '') {
            return $node;
        }

        return trim((string) ($meta['node'] ?? ''));
    }
}
