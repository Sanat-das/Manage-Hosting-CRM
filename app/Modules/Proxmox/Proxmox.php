<?php

declare(strict_types=1);

namespace App\Modules\Proxmox;

use App\Contracts\Integrations\AbstractComputeModule;
use App\Contracts\Integrations\PanelException;
use App\Contracts\Integrations\PanelProvisionRequest;
use App\Contracts\Integrations\ProvisioningResult;
use App\Contracts\Integrations\ServerConnectionResult;
use App\Contracts\Integrations\ServerInfoDTO;
use App\Models\PanelAccount;
use App\Models\ProvisioningEvent;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Modules\Proxmox\Services\ProxmoxClient;
use App\Services\Provisioning\ProvisioningEventRecorder;
use App\Services\Provisioning\ProxmoxTemplateCatalog;
use App\Services\Provisioning\VmGuestCredentialStore;
use Illuminate\Support\Facades\Log;

/**
 * Proxmox VE provisioning module.
 *
 * Creates and manages QEMU/KVM virtual machines on a PVE node/cluster, built on
 * `App\Contracts\Integrations\AbstractComputeModule`.
 *
 * Two PVE facts drive the whole design:
 *
 *  - **The API is task-based.** Cloning, resizing and power actions answer with
 *    a UPID long before the work is done, so `ProxmoxClient::waitForTask()` is
 *    what makes provisioning synchronous. Success is never reported on the
 *    strength of a queued task alone.
 *  - **A VMID is permanent and not reusable while the VM exists.** It is the
 *    `panel_accounts.external_id` every later action addresses, so a provision
 *    that cannot establish one fails loudly instead of recording a machine
 *    nothing can manage.
 */
final class Proxmox extends AbstractComputeModule
{
    /** PVE's own VMID floor. Below 100 is reserved for PVE's internal IDs. */
    private const DEFAULT_VMID_FLOOR = 100;

    public function configSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'plan', 'label' => 'Plan (label only)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'cpu', 'label' => 'vCPUs', 'type' => 'number', 'required' => false, 'default' => 2],
                ['key' => 'ram', 'label' => 'RAM (MB)', 'type' => 'number', 'required' => false, 'default' => 2048],
                ['key' => 'disk', 'label' => 'Disk (GB) (grow-only, cloned VMs inherit the template disk)', 'type' => 'number', 'required' => false, 'default' => 50],
                ['key' => 'node', 'label' => 'PVE node (blank = first online node)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'template_vmid', 'label' => 'Template VMID (blank = build an empty VM)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'storage', 'label' => 'Storage pool (blank = node default)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'vmid_floor', 'label' => 'Lowest VMID to allocate', 'type' => 'number', 'required' => false, 'default' => self::DEFAULT_VMID_FLOOR],
                ['key' => 'vmid', 'label' => 'Fixed VMID (blank = next free; errors if taken)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'bridge', 'label' => 'Network bridge', 'type' => 'text', 'required' => false, 'default' => 'vmbr0'],
                ['key' => 'vlan_tag', 'label' => 'VLAN tag (blank = untagged)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'iso', 'label' => 'ISO filename on the storage (empty-VM builds only)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'iso_storage', 'label' => 'ISO storage pool (empty-VM builds; blank = first ISO-capable pool)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'start_after_create', 'label' => 'Start the VM after creation', 'type' => 'checkbox', 'required' => false, 'default' => true],
                ['key' => 'full_clone', 'label' => 'Full clone (uncheck for a linked clone)', 'type' => 'checkbox', 'required' => false, 'default' => true],
                ['key' => 'clone_timeout', 'label' => 'Clone timeout (seconds)', 'type' => 'number', 'required' => false, 'default' => 900],
                ['key' => 'delete_vm_on_terminate', 'label' => 'Destroy the VM on terminate (unchecked stops it instead)', 'type' => 'checkbox', 'required' => false, 'default' => true],
                ['key' => 'guest_username', 'label' => 'Guest username (applied on cloud-init templates)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'guest_password', 'label' => 'Guest password (cloud-init templates; blank = the generated panel password)', 'type' => 'password', 'encrypted' => true, 'default' => ''],
                ['key' => 'contact_email', 'label' => 'Contact email override', 'type' => 'text', 'required' => false, 'default' => ''],
            ],
        ];
    }

    public function serverConfigSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'host', 'label' => 'PVE host / IP', 'type' => 'text', 'required' => true, 'default' => ''],
                ['key' => 'port', 'label' => 'API port', 'type' => 'number', 'required' => false, 'default' => ProxmoxClient::DEFAULT_PORT],
                [
                    'key' => 'auth_type', 'label' => 'Authentication', 'type' => 'select', 'required' => true, 'default' => 'token',
                    'options' => ['token' => 'API token (recommended)', 'ticket' => 'Username + password (ticket)'],
                ],
                ['key' => 'api_username', 'label' => 'Token ID — user@realm!tokenid (token auth)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'api_password', 'label' => 'Token secret (token auth)', 'type' => 'password', 'encrypted' => true, 'required' => false, 'default' => ''],
                ['key' => 'ticket_username', 'label' => 'Username — user@realm, e.g. root@pam (ticket auth)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'verify_tls', 'label' => 'Verify the TLS certificate', 'type' => 'checkbox', 'required' => false, 'default' => true],
            ],
        ];
    }

    protected function panel(): string
    {
        return 'proxmox';
    }

    protected function credentialHint(): string
    {
        return 'set host, port and either an API token id + secret or a username + password';
    }

    protected function serverIsConfigured(?Server $server): bool
    {
        return ProxmoxClient::isConfigured($server);
    }

    // ─────────────────────────── server-type surface ───────────────────────────

    public function testConnection(Server $server): ServerConnectionResult
    {
        return (new ProxmoxClient($server))->testConnection();
    }

    public function getServerInfo(Server $server): ServerInfoDTO
    {
        return (new ProxmoxClient($server))->getServerInfo();
    }

    /**
     * Short-TTL server info for the admin server-show page. Single cache home is
     * ProxmoxClient::cachedServerInfo() (60s, keyed by server id).
     */
    public function getCachedServerInfo(Server $server, int $ttlSeconds = 60): ServerInfoDTO
    {
        return (new ProxmoxClient($server))->cachedServerInfo($ttlSeconds);
    }

    /**
     * Every cloneable template across all reachable nodes.
     *
     * `listTemplates()` answers for one node, but a cluster splits its templates
     * across nodes — curating from a single node would silently hide the rest.
     * Unreachable nodes are skipped rather than failing the whole discovery, so
     * one dead node cannot make the picker empty.
     *
     * Only entries PVE marks as templates are returned: cloning a non-template
     * guest is not the supported path, and offering it invites mid-clone
     * failures.
     *
     * Failure is NOT "no templates". A credential with no effective privileges
     * sees zero VMs on every node, and a cluster where every node errors is a
     * discovery failure; both throw so the admin picker can say why instead of
     * showing a false "nothing to curate". A cluster that answers but genuinely
     * has no templates still returns [].
     *
     * @return list<array{vmid: int, name: string, node: string, status: string, template: bool}>
     *
     * @throws PanelException
     */
    public function discoverTemplates(Server $server): array
    {
        $client = new ProxmoxClient($server);

        // A privilege-separated API token with no ACL authenticates and answers
        // /nodes, but every VM listing comes back empty — indistinguishable
        // from "this cluster has no templates" unless probed, which is exactly
        // the false-green testConnection() refuses to report.
        if ($client->effectivePrivileges() === []) {
            throw new PanelException(
                'This Proxmox VE credential has no effective privileges (/access/permissions is empty), '
                .'so template discovery cannot see any VM. Grant the token an ACL with a role such as PVEVMAdmin.',
            );
        }

        $nodes = $client->cachedNodes();
        $out = [];
        $failed = [];

        foreach ($nodes as $node) {
            try {
                foreach ($client->listTemplates($node) as $template) {
                    if ($template['template'] === true) {
                        $out[] = $template;
                    }
                }
            } catch (\Throwable $e) {
                // Offline or unreachable node: skip it, keep discovering the
                // rest — but leave a trace, otherwise an auth failure looks
                // exactly like "this node has no templates".
                $failed[] = $node;
                Log::warning('Proxmox template discovery skipped a node', [
                    'server_id' => $server->id,
                    'node' => $node,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($out === [] && $failed !== [] && count($failed) === count($nodes)) {
            throw new PanelException(sprintf(
                'Proxmox VE template discovery failed on every node (%s). Check the API credential and node health.',
                implode(', ', $failed),
            ));
        }

        usort($out, static fn (array $a, array $b): int => $a['vmid'] <=> $b['vmid']);

        return $out;
    }

    /**
     * Live per-VM inventory for the admin server-show VM table.
     *
     * @return list<array<string, mixed>>
     */
    public function listVms(Server $server): array
    {
        return (new ProxmoxClient($server))->listVms();
    }

    /**
     * Templates available to clone, for the admin UI. Empty on any failure so a
     * node hiccup cannot break the page — logged so an auth failure is
     * diagnosable instead of looking like "no templates".
     *
     * @return list<array{vmid: int, name: string, type: string, node: string, status: string, template: bool}>
     */
    public function listTemplates(Server $server, ?string $node = null): array
    {
        $client = new ProxmoxClient($server);
        $node = ($node !== null && trim($node) !== '') ? trim($node) : null;

        try {
            return $client->listTemplates($node ?? $client->defaultNode());
        } catch (\Throwable $e) {
            Log::warning('Proxmox template listing failed', [
                'server_id' => $server->id,
                'node' => $node,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    // ───────────────────────────── provision ─────────────────────────────

    /**
     * Host-verified provision: an active record whose VM is missing on the node
     * (destroyed out-of-band, or a partial failure) must rebuild rather than
     * return a false "already provisioned". The stale row is flipped to
     * terminated so the parent's idempotency check proceeds to createRemote and
     * the same row is re-activated via updateOrCreate.
     */
    public function provision(ServiceInstance $service, array $config): ProvisioningResult
    {
        $existing = $this->accountFor($service);

        if ($existing !== null && $existing->status === PanelAccount::STATUS_ACTIVE) {
            $probe = $this->probeRecordedVm($existing);

            if (($probe['exists'] ?? null) === true) {
                return ProvisioningResult::ok('Proxmox resource already provisioned', [
                    'username' => $existing->username,
                    'external_id' => $existing->external_id ?? $existing->username,
                ]);
            }

            if (($probe['exists'] ?? null) === false) {
                try {
                    $existing->update(['status' => PanelAccount::STATUS_TERMINATED]);
                } catch (\Throwable $e) {
                    Log::warning('Proxmox stale record flip failed', [
                        'panel_account_id' => $existing->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                return ProvisioningResult::fail($probe['error'] ?? 'Node probe failed.');
            }
        }

        return parent::provision($service, $config);
    }

    /**
     * Is the VM behind this panel account still on its node?
     *
     * The same hook HyperV exposes, and for the same two callers:
     *
     *  - `ManualProvisioner`'s idempotency check, which otherwise falls back to
     *    blind "already provisioned" behaviour for a driver that lacks it —
     *    short-circuiting without verifying the machine exists;
     *  - the admin move action, which must know whether a service really has a
     *    machine before re-pointing it at another server.
     *
     * `exists` is deliberately nullable: `true`/`false` are verified answers,
     * `null` means the node could not be reached, and callers must treat that as
     * unknown (fail safe) rather than as "gone".
     *
     * @return array{exists: bool|null, error?: string}
     */
    public function recordedVmState(PanelAccount $account): array
    {
        return $this->probeRecordedVm($account);
    }

    /**
     * Graceful guest reboot. NOT part of ProvisioningModule — called directly
     * by HostingController::moduleAction() / the client vmPower endpoint after
     * confirmation, mirroring HyperV::restart(). No local billing status
     * change: a reboot leaves billing state untouched.
     */
    public function restart(ServiceInstance $service, array $config): ProvisioningResult
    {
        $account = $this->accountFor($service);

        if ($account === null) {
            return ProvisioningResult::fail('No Proxmox VE VM is recorded for this service.');
        }

        $server = $service->server;

        if ($server === null || ! $this->serverIsConfigured($server)) {
            return ProvisioningResult::fail(sprintf(
                'Server "%s" is not configured for proxmox (%s).',
                $server?->name ?? $service->server_id,
                $this->credentialHint(),
            ));
        }

        try {
            $client = new ProxmoxClient($server);
            $node = $this->nodeFor($client, $account);
            $vmid = $this->vmidFor($account);

            // A reboot on a stopped VM would either fail mid-task or surprise
            // start it depending on PVE version — refuse it explicitly, matching
            // the Hyper-V restart guard and the UI's promise.
            if ($client->vmStatus($node, $vmid) !== 'running') {
                return ProvisioningResult::fail(sprintf(
                    'Proxmox VE VM %d is not running — restart is refused. Start it first.',
                    $vmid,
                ));
            }

            $client->rebootVm($node, $vmid);
        } catch (PanelException $e) {
            return ProvisioningResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            return ProvisioningResult::fail('Proxmox VE restart failed unexpectedly: '.$e->getMessage());
        }

        return ProvisioningResult::ok("Proxmox VE VM {$vmid} restarted", [
            'username' => $account->username,
            'external_id' => (string) $vmid,
            'state' => 'Running',
        ]);
    }

    /**
     * Reset a guest OS password through the QEMU guest agent, falling back to
     * cloud-init when the agent cannot do it.
     *
     * Mirrors HyperV::resetGuestAdminPassword(): the VM must be running, the
     * username defaults to the stored guest login (`root` when nothing is
     * stored), and the new password is written back to the credential store on
     * success. `$currentPassword` exists for interface parity only — the PVE
     * agent path is host-authoritative and never needs it.
     */
    public function resetGuestAdminPassword(ServiceInstance $service, string $newPassword, ?string $username = null, ?string $currentPassword = null): ProvisioningResult
    {
        $account = $this->accountFor($service);

        if ($account === null) {
            return ProvisioningResult::fail('No Proxmox VE VM is recorded for this service.');
        }

        $server = $service->server;

        if ($server === null || ! $this->serverIsConfigured($server)) {
            return ProvisioningResult::fail(sprintf(
                'Server "%s" is not configured for proxmox (%s).',
                $server?->name ?? $service->server_id,
                $this->credentialHint(),
            ));
        }

        try {
            $vmid = $this->vmidFor($account);
        } catch (PanelException $e) {
            return ProvisioningResult::fail($e->getMessage());
        }

        $client = new ProxmoxClient($server);

        try {
            $node = $this->nodeFor($client, $account);

            // A password set on a stopped guest would either fail inside the
            // agent or silently do nothing — refuse it explicitly, matching
            // the Hyper-V reset guard.
            if (strtolower(trim($client->vmStatus($node, $vmid))) !== 'running') {
                return ProvisioningResult::fail('Start the VM before resetting the password.');
            }
        } catch (PanelException $e) {
            return ProvisioningResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            return ProvisioningResult::fail('Proxmox VE password reset failed unexpectedly: '.$e->getMessage());
        }

        $storedUsername = null;

        try {
            $stored = app(VmGuestCredentialStore::class)->read($account);
            $storedUsername = $stored['username'] ?? null;
        } catch (\Throwable) {
            // An unreadable store degrades to the default login.
        }

        $resolvedUsername = $username !== null && trim($username) !== '' ? trim($username) : null;

        if ($resolvedUsername === null && $storedUsername !== null && trim($storedUsername) !== '') {
            $resolvedUsername = trim($storedUsername);
        }

        if ($resolvedUsername === null || $resolvedUsername === '') {
            $resolvedUsername = 'root';
        }

        try {
            $client->setGuestPassword($node, $vmid, $resolvedUsername, $newPassword);
        } catch (\Throwable $agentException) {
            $agentError = $agentException->getMessage();

            // The cloud-init fallback is only for agent-unavailability (not
            // installed, not running, or an old guest): any other agent
            // failure — auth/permission errors such as a 403, for example —
            // must fail loudly with the agent error instead of being masked
            // by a staged cloud-init password.
            $agentUnavailable = stripos($agentError, 'agent') !== false;

            if ($agentUnavailable) {
                // The agent is unavailable (not installed, not running, or an
                // old guest): when the VM carries a cloud-init drive the
                // password can still be staged through cipassword for the
                // next boot.
                try {
                    if ($client->hasCloudInitDrive($node, $vmid)
                        && $client->applyCloudInitCredentials($node, $vmid, $resolvedUsername, $newPassword)) {
                        $this->storeGuestCredentials($account, $resolvedUsername, $newPassword);

                        return ProvisioningResult::ok(
                            sprintf('Proxmox VE VM %d password updated via cloud-init — the change applies on next boot.', $vmid),
                            [
                                'username' => $resolvedUsername,
                                'password' => $newPassword,
                                'external_id' => (string) $vmid,
                            ],
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('Proxmox cloud-init password fallback failed', [
                        'panel_account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return ProvisioningResult::fail('Proxmox VE password reset failed: '.$agentError);
        }

        $this->storeGuestCredentials($account, $resolvedUsername, $newPassword);

        return ProvisioningResult::ok(
            sprintf('Proxmox VE VM %d guest password reset', $vmid),
            [
                'username' => $resolvedUsername,
                'password' => $newPassword,
                'external_id' => (string) $vmid,
            ],
        );
    }

    /**
     * Write the new guest login back to the credential store. Never throws:
     * the password is already live inside the guest.
     */
    private function storeGuestCredentials(PanelAccount $account, string $username, string $newPassword): void
    {
        try {
            app(VmGuestCredentialStore::class)->store($account, $username, $newPassword);
        } catch (\Throwable $e) {
            Log::warning('Proxmox guest credential store failed after password reset', [
                'panel_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Is the VM this row claims still on the node?
     *
     * @return array{exists: bool|null, error?: string}
     */
    private function probeRecordedVm(PanelAccount $account): array
    {
        $externalId = trim((string) ($account->external_id ?? ''));

        if ($externalId === '') {
            return ['exists' => false];
        }

        $server = $account->server_id !== null ? Server::find($account->server_id) : null;

        if ($server === null) {
            return ['exists' => null, 'error' => 'The server this VM was built on no longer exists.'];
        }

        $client = new ProxmoxClient($server);
        $node = $this->recordedNode($account);

        try {
            if ($node === '') {
                $node = $client->defaultNode();
            }

            return $client->vmExists($node, (int) $externalId);
        } catch (\Throwable $e) {
            return ['exists' => null, 'error' => $e->getMessage()];
        }
    }

    protected function createRemote(PanelProvisionRequest $request): array
    {
        $client = new ProxmoxClient($request->server);
        $config = $request->config;

        $cpu = max(1, (int) $request->config('cpu', (string) ($config['vcpus'] ?? 2)));
        $ramMb = max(128, (int) $request->config('ram', (string) ($config['memory'] ?? 2048)));
        $diskGb = max(1, (int) $request->config('disk', (string) ($config['disk_gb'] ?? 50)));

        $node = $request->config('node');
        if ($node === '') {
            $node = $client->defaultNode();
        }

        $vmid = $this->resolveVmId($client, $request);
        $name = $this->vmNameFor($request);
        $template = $this->resolveTemplate($request, $client);
        $warnings = [];

        // Legacy rows saved before `start_after_create` existed read the schema
        // default (true), exactly like terminateRemote()'s
        // delete_vm_on_terminate normalisation.
        $startAfterCreate = array_key_exists('start_after_create', $config)
            ? (bool) $config['start_after_create']
            : true;

        // Set once the VM exists on the node: a later failure must remove the
        // orphan instead of leaking a VMID that nothing records.
        $created = false;
        $running = false;
        $guestUser = trim((string) ($config['guest_username'] ?? ''));
        $appliedGuestPassword = '';

        try {
            if ($template !== null) {
                $this->reportStage($request->service, 'cloning');

                // A pinned pool is validated before the clone starts; otherwise
                // PVE places the disk on the target's default.
                $pinnedStorage = $request->config('storage');
                $storage = $pinnedStorage !== '' ? $client->storageFor($node, $pinnedStorage) : null;

                $client->cloneVm(
                    templateNode: $template['node'],
                    templateVmid: $template['vmid'],
                    newVmid: $vmid,
                    name: $name,
                    targetNode: $node,
                    storage: $storage,
                    full: (bool) ($config['full_clone'] ?? true),
                    timeoutSeconds: max(1, (int) ($config['clone_timeout'] ?? 900)),
                );

                $created = true;

                $this->reportStage($request->service, 'configuring');

                // A clone inherits the template's hardware; the product's spec
                // is only advisory until it is applied here.
                $client->applySpec($node, $vmid, $cpu, $ramMb, $diskGb);
            } else {
                $this->reportStage($request->service, 'creating');

                // An empty build must name a pool, so an unpinned product gets
                // the roomiest usable one (validated, not guessed).
                $storage = $client->storageFor($node, $request->config('storage'));

                $iso = $request->config('iso');
                $isoStorage = null;

                if ($iso !== '') {
                    // ISOs usually live on a different pool than the VM disk;
                    // attaching one to a disk-only pool is rejected by PVE.
                    $iso = $client->assertIsoName($iso);
                    $isoStorage = $client->storageForIso($node, $request->config('iso_storage'));

                    if (! $client->isoExists($node, $isoStorage, $iso)) {
                        throw new PanelException(sprintf(
                            'ISO "%s" was not found on storage "%s" of node "%s".',
                            $iso,
                            $isoStorage,
                            $node,
                        ));
                    }
                }

                $client->createVm(
                    node: $node,
                    vmid: $vmid,
                    name: $name,
                    cpu: $cpu,
                    ramMb: $ramMb,
                    diskGb: $diskGb,
                    storage: $storage,
                    iso: $iso !== '' ? $iso : null,
                    bridge: $request->config('bridge') !== '' ? $request->config('bridge') : null,
                    vlanTag: $request->config('vlan_tag') !== '' ? $request->config('vlan_tag') : null,
                    isoStorage: $isoStorage,
                );

                $created = true;
                $warnings[] = 'no template configured — the VM was built empty, so the guest still needs an OS install';
            }

            // Host-verified: never report success before the VM provably exists.
            $this->reportStage($request->service, 'verifying');

            $verify = $client->vmExists($node, $vmid);

            if ($verify['exists'] !== true) {
                throw new PanelException(
                    'Proxmox VE reported the task finished but the VM is not present on the node — nothing was recorded.',
                );
            }

            $running = $verify['status'] === 'running';

            // Guest credentials: PVE consumes ciuser/cipassword only through a
            // cloud-init drive. Without one the keys are inert, so they are NOT
            // reported back — a guest username paired with a password that
            // exists nowhere would be mailed to the customer as a working login.
            if ($guestUser !== '') {
                $effectivePassword = trim((string) ($config['guest_password'] ?? ''));

                if ($effectivePassword === '') {
                    $effectivePassword = $request->password;
                }

                if ($client->applyCloudInitCredentials($node, $vmid, $guestUser, $effectivePassword)) {
                    $appliedGuestPassword = $effectivePassword;
                } else {
                    $warnings[] = sprintf(
                        'guest credentials for "%s" were not applied: the clone has no cloud-init drive, so it keeps the template\'s baked-in login',
                        $guestUser,
                    );
                }
            } elseif (trim((string) ($config['guest_password'] ?? '')) !== '') {
                $warnings[] = 'guest_password was ignored: set guest_username too, so the module knows which account the password belongs to';
            }

            if ($startAfterCreate && ! $running) {
                $this->reportStage($request->service, 'starting');

                try {
                    $client->startVm($node, $vmid);
                    $running = true;
                } catch (PanelException $e) {
                    // The VM exists; a failed start is a warning, never a creation
                    // failure, otherwise the caller would retry and orphan the VM.
                    $warnings[] = 'VM created but failed to start: '.$e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $note = $created ? $this->discardFailedBuild($client, $node, $vmid) : '';

            if ($note !== '' && $e instanceof PanelException) {
                throw new PanelException($e->getMessage().' — '.$note, previous: $e);
            }

            throw $e;
        }

        $result = [
            'external_id' => (string) $vmid,
            'ip' => (string) ($request->server->ip_address ?? ''),
            'meta' => [
                'node' => $node,
                'vmid' => $vmid,
                'name' => $name,
                'template_vmid' => isset($template['vmid']) ? (string) $template['vmid'] : null,
                'template_node' => $template['node'] ?? null,
                'cpu' => $cpu,
                'ram' => $ramMb,
                'ramMb' => $ramMb,
                'disk' => $diskGb,
                'diskGb' => $diskGb,
                'running' => $running,
                'built_at' => now()->toIso8601String(),
            ],
        ];

        if ($warnings !== []) {
            $result['warning'] = implode('; ', $warnings);
        }

        if ($guestUser !== '' && $appliedGuestPassword !== '') {
            $result['guest_username'] = $guestUser;
            $result['guest_password'] = $appliedGuestPassword;
        }

        return $result;
    }

    /**
     * Best-effort removal of a VM whose build failed after it was created.
     *
     * The base class only records a panel account on success, so a failure
     * between "the VM exists" and "the record is written" (resize, config
     * update, existence probe) would leave a machine nothing addresses and a
     * VMID the next provision cannot reuse. Removing it keeps the node and the
     * VMID pool truthful. A cleanup failure is logged and never replaces the
     * original error; PVE 500s for an already-gone VM, which is the desired end
     * state and is tolerated through the same `isMissingVm()` check terminate
     * uses.
     *
     * @return string a note appended to the reported error, or '' when there is
     *                nothing to say (removed cleanly or already gone)
     */
    private function discardFailedBuild(ProxmoxClient $client, string $node, int $vmid): string
    {
        try {
            $client->destroyVm($node, $vmid);

            return 'the partial VM was removed before anything was recorded';
        } catch (PanelException $e) {
            if ($client->isMissingVm($e->getMessage())) {
                return '';
            }
        } catch (\Throwable) {
            // Fall through to the manual-cleanup note below.
        }

        Log::warning('Proxmox could not clean up a partially built VM', [
            'node' => $node,
            'vmid' => $vmid,
        ]);

        return sprintf(
            'the partial VM could not be removed automatically — check node "%s" for VMID %d',
            $node,
            $vmid,
        );
    }

    /**
     * Which template this provision should clone from, or null to build empty.
     *
     * Resolution order and enforcement:
     *
     *  1. The server's curated list is narrowed by the product's
     *     `allowed_templates` (empty allow-list = everything curated).
     *  2. An explicit product `template_vmid` must be inside that narrowed set —
     *     a restricted product cannot reach a template outside its tier.
     *  3. Otherwise the server's default is used, but only if it is also inside
     *     the narrowed set, so the default can never widen a restriction.
     *  4. If templates are curated but none resolves, that is an operator error
     *     worth failing on — silently building an empty VM instead would ship a
     *     machine with no OS.
     *  5. Nothing curated at all means the operator is not using templates yet:
     *     an explicit VMID is then honoured as given (its node is looked up live),
     *     and with no explicit VMID the VM is built empty.
     *
     * @return array{vmid: int, node: string}|null
     *
     * @throws PanelException
     */
    private function resolveTemplate(PanelProvisionRequest $request, ProxmoxClient $client): ?array
    {
        $server = $request->server;
        $explicit = $request->config('template_vmid');

        $allowedRaw = $request->config['allowed_templates'] ?? null;
        $allowed = ProxmoxTemplateCatalog::sanitizeAllowed(is_array($allowedRaw) ? $allowedRaw : []);
        $effective = ProxmoxTemplateCatalog::effectiveTemplates($server, $allowed);

        $curated = $server->proxmoxTemplates();

        $pick = static function (array $template) use ($client, $server): array {
            $node = $template['node'] !== '' ? $template['node'] : '';

            if ($node === '') {
                // Curated before the node was known; resolve it live.
                $node = (string) $client->nodeHoldingVm((int) $template['vmid']);
            }

            if ($node === '') {
                throw new PanelException(sprintf(
                    'Proxmox VE template VMID %s cannot be located on any node of server "%s".',
                    $template['vmid'],
                    $server->name,
                ));
            }

            return ['vmid' => (int) $template['vmid'], 'node' => $node];
        };

        if ($curated === []) {
            // Templates are not curated on this server: honour the raw VMID.
            if ($explicit === '') {
                return null;
            }

            $node = $client->nodeHoldingVm((int) $explicit);

            if ($node === null) {
                throw new PanelException(sprintf(
                    'Proxmox VE template VMID %s was not found on any node of server "%s".',
                    $explicit,
                    $server->name,
                ));
            }

            return ['vmid' => (int) $explicit, 'node' => $node];
        }

        if ($explicit !== '') {
            foreach ($effective as $template) {
                if ($template['vmid'] === $explicit) {
                    return $pick($template);
                }
            }

            throw new PanelException(sprintf(
                "Template VMID %s is not available to this product on server '%s'%s.",
                $explicit,
                $server->name,
                $effective === []
                    ? ' — this product is restricted to none of the curated templates'
                    : ' (available: '.implode(', ', array_column($effective, 'vmid')).')',
            ));
        }

        $default = $server->proxmoxDefaultTemplate();

        if ($default !== null) {
            foreach ($effective as $template) {
                if ($template['vmid'] === $default) {
                    return $pick($template);
                }
            }
        }

        if ($effective === []) {
            throw new PanelException(sprintf(
                "No template is selected for this product and server '%s' has no usable default — "
                .'this product is restricted to none of the %d curated template(s).',
                $server->name,
                count($curated),
            ));
        }

        throw new PanelException(sprintf(
            "No template is selected for this product and server '%s' has no usable default (%s curated). "
            .'Pick one of: %s — or set a default template on the server.',
            $server->name,
            count($curated),
            implode(', ', array_column($effective, 'vmid')),
        ));
    }

    /**
     * A fixed VMID wins when the product sets one; otherwise the next free id at
     * or above the floor.
     *
     * A pinned id is verified up front so a collision fails with a clear reason
     * before any clone work starts, rather than surfacing as a raw PVE error
     * mid-operation.
     */
    private function resolveVmId(ProxmoxClient $client, PanelProvisionRequest $request): int
    {
        $fixed = (int) $request->config('vmid');

        if ($fixed > 0) {
            if (! $client->vmIdIsFree($fixed)) {
                throw new PanelException(sprintf(
                    'VMID %d is already in use on this Proxmox VE cluster — pick another fixed VMID or leave it blank.',
                    $fixed,
                ));
            }

            return $fixed;
        }

        $floor = (int) $request->config('vmid_floor', (string) self::DEFAULT_VMID_FLOOR);

        return $client->nextVmId(max($floor, self::DEFAULT_VMID_FLOOR));
    }

    /**
     * VM name: the product hostname when one exists, else the derived username.
     * PVE caps names at 63 chars.
     */
    private function vmNameFor(PanelProvisionRequest $request): string
    {
        $candidates = [
            trim((string) ($request->config['host_name'] ?? '')),
            trim((string) ($request->service->username ?? '')),
            $request->username,
            $request->domain,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return substr(preg_replace('/[^A-Za-z0-9._-]/', '-', $candidate) ?? $candidate, 0, 63);
            }
        }

        return 'vm'.$request->service->id;
    }

    // ───────────────────────────── lifecycle ─────────────────────────────

    protected function suspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        $client = new ProxmoxClient($server);
        $node = $this->nodeFor($client, $account);
        $vmid = $this->vmidFor($account);

        $status = $client->vmStatus($node, $vmid);

        // Suspending an already-stopped VM is a no-op, not a failure: re-running
        // a suspend must not fail because the customer shut the guest down.
        if ($status !== 'running') {
            return;
        }

        $client->suspendAccount($node, $vmid);
    }

    protected function unsuspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        $client = new ProxmoxClient($server);
        $node = $this->nodeFor($client, $account);
        $vmid = $this->vmidFor($account);

        if ($client->vmStatus($node, $vmid) === 'running') {
            return;
        }

        $client->startVm($node, $vmid);
    }

    protected function terminateRemote(PanelAccount $account, Server $server, array $config): void
    {
        $client = new ProxmoxClient($server);
        $node = $this->nodeFor($client, $account);
        $vmid = $this->vmidFor($account);

        // Default true: an existing install has no `delete_vm_on_terminate` key
        // yet, and leaving the disks behind would block the VMID forever.
        if (! array_key_exists('delete_vm_on_terminate', $config)) {
            $config['delete_vm_on_terminate'] = true;
        }

        if (empty($config['delete_vm_on_terminate'])) {
            if ($client->vmStatus($node, $vmid) === 'running') {
                $client->stopVm($node, $vmid);
            }

            return;
        }

        try {
            $client->destroyVm($node, $vmid);
        } catch (PanelException $e) {
            // PVE 500s when the VM is already gone; that is the desired end
            // state, so it must not block terminating the service record.
            if ($client->isMissingVm($e->getMessage())) {
                return;
            }

            throw $e;
        }
    }

    /**
     * The node the VM lives on. Prefer the node recorded at build time, then any
     * node that actually has the VM, then the default node. A PVE cluster shares
     * VMIDs, so a wrong node is a hard failure — "does not have this VM" from a
     * different node must never be treated as "deleted".
     */
    private function nodeFor(ProxmoxClient $client, PanelAccount $account): string
    {
        $recorded = $this->recordedNode($account);
        $vmid = $this->vmidFor($account);

        if ($recorded !== '') {
            try {
                if ($client->vmExists($recorded, $vmid)['exists'] === true) {
                    return $recorded;
                }
            } catch (PanelException) {
                // Fall through to a cluster-wide search.
            }
        }

        foreach ($client->cachedNodes() as $node) {
            try {
                if ($client->vmExists($node, $vmid)['exists'] === true) {
                    return $node;
                }
            } catch (PanelException) {
                continue;
            }
        }

        if ($recorded !== '') {
            return $recorded;
        }

        return $client->defaultNode();
    }

    /** The node recorded in the panel account meta at build time. */
    private function recordedNode(PanelAccount $account): string
    {
        $meta = is_array($account->meta) ? $account->meta : [];
        $inner = is_array($meta['meta'] ?? null) ? $meta['meta'] : $meta;

        return trim((string) ($inner['node'] ?? ''));
    }

    private function vmidFor(PanelAccount $account): int
    {
        $vmid = (int) trim((string) ($account->external_id ?? ''));

        if ($vmid <= 0) {
            throw new PanelException(
                'This service has no Proxmox VE VMID recorded, so its VM cannot be addressed. Re-provision the service.',
            );
        }

        return $vmid;
    }

    /**
     * Report a provisioning stage against the running event. Mirrors
     * HyperV::reportStage() — same pipeline, same stages. Never throws:
     * progress reporting must not be able to break provisioning.
     */
    private function reportStage(ServiceInstance $service, string $stage): void
    {
        try {
            $event = ProvisioningEvent::where('service_instance_id', $service->id)
                ->where('status', 'running')
                ->orderByDesc('id')
                ->first();

            if ($event !== null) {
                app(ProvisioningEventRecorder::class)->progress($event, $stage);
            }
        } catch (\Throwable) {
            // Progress reporting must never break provisioning.
        }
    }
}
