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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class HyperV extends AbstractComputeModule
{
    public function configSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'plan', 'label' => 'Plan / hardware profile', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'vm', 'label' => 'VM name template', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'cpu', 'label' => 'vCPUs (fallback — Configuration Options take precedence)', 'type' => 'number', 'required' => false, 'default' => 2],
                ['key' => 'ram', 'label' => 'RAM (MB) (fallback — Configuration Options take precedence)', 'type' => 'number', 'required' => false, 'default' => 2048],
                ['key' => 'disk', 'label' => 'Disk (GB) (fallback — Configuration Options take precedence)', 'type' => 'number', 'required' => false, 'default' => 50],
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

    /**
     * Host-verified provision: an active record whose VM is missing on the
     * host (deleted out-of-band / partial failure) must rebuild instead of
     * returning a false "already provisioned" success. The stale row is
     * flipped to terminated so the parent's idempotency check proceeds to
     * createRemote and the same row is re-activated via updateOrCreate.
     */
    public function provision(ServiceInstance $service, array $config): ProvisioningResult
    {
        $existing = $this->accountFor($service);
        if ($existing !== null && $existing->status === PanelAccount::STATUS_ACTIVE) {
            $probe = $this->recordedVmState($existing);
            if (($probe['exists'] ?? null) === true) {
                return ProvisioningResult::ok('Hyperv resource already provisioned', [
                    'username' => $existing->username,
                    'external_id' => $existing->external_id ?? $existing->username,
                ]);
            }
            if (($probe['exists'] ?? null) === false) {
                try {
                    $existing->update(['status' => PanelAccount::STATUS_TERMINATED]);
                } catch (\Throwable $e) {
                    Log::warning('Hyper-V stale record flip failed', [
                        'panel_account_id' => $existing->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                return ProvisioningResult::fail($probe['error'] ?? 'Host probe failed.');
            }
        }

        return parent::provision($service, $config);
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

        // Template resolution with per-product restriction: explicit must be in effective, default must be in effective.
        $explicit = trim((string) ($request->config['template_vm'] ?? ''));
        $server = $request->server;
        $curated = $server?->hypervTemplateVms() ?? [];
        $default = $server?->hypervDefaultTemplate();

        $allowedRaw = $request->config['allowed_templates'] ?? null;
        $allowed = is_array($allowedRaw) ? \App\Services\Provisioning\HypervTemplateCatalog::sanitizeAllowed($allowedRaw) : [];
        $effective = $server !== null ? \App\Services\Provisioning\HypervTemplateCatalog::effectiveNames($server, $allowed) : $curated;
        // When effective is computed but server is null, fallback to curated logic already.

        // Normalize default: if it is not in effective, treat as no default
        $effectiveDefault = null;
        if ($default !== null && $default !== '' && in_array($default, $effective, true)) {
            $effectiveDefault = $default;
        } elseif ($default !== null && $default !== '' && $allowed !== [] && ! in_array($default, $effective, true)) {
            $effectiveDefault = null;
        } elseif ($default !== null && $default !== '') {
            $effectiveDefault = $default;
        }

        $template = null;

        if ($explicit !== '') {
            if (! in_array($explicit, $effective, true)) {
                $serverLabel = $server?->name ?? (string) ($server?->id ?? 'unknown');
                throw new PanelException(sprintf("Template '%s' is not in the curated list for server '%s'.", $explicit, $serverLabel));
            }

            $template = $explicit;
        } elseif ($effectiveDefault !== null && $effectiveDefault !== '') {
            $template = $effectiveDefault;
        } elseif ($effective !== []) {
            $serverLabel = $server?->name ?? (string) ($server?->id ?? 'unknown');
            throw new PanelException(sprintf("no template selected and no default template configured for server '%s'.", $serverLabel));
        } elseif ($curated !== [] && $effective === []) {
            $serverLabel = $server?->name ?? (string) ($server?->id ?? 'unknown');
            throw new PanelException(sprintf("no template is allowed for this product on server '%s'.", $serverLabel));
        } else {
            $template = null;
        }

        if ($template !== null && $template !== '') {
            $result = $client->cloneFromTemplate($vmName, $template, $cpu, $ramMb, $diskGb, $switch);
        } else {
            $result = $client->createVm($vmName, $cpu, $ramMb, $diskGb, $switch, $generation);
        }

        if (isset($result['error'])) {
            throw new PanelException('Hyper-V VM creation failed: '.$result['error']);
        }

        // Host-verified: never report success before the VM provably exists.
        $this->reportStage($request->service, 'verifying');
        $verify = $client->getVmState($vmName, $result['external_id'] ?? null);
        if (isset($verify['error']) || ($verify['exists'] ?? false) !== true) {
            throw new PanelException('Host reported the VM was created but it is not present on the host — nothing was recorded.');
        }

        // Optional start_after_create: best-effort, record warning but never fail creation.
        $vmRunning = strtolower((string) ($verify['state'] ?? '')) === 'running';
        if (! empty($request->config['start_after_create'])) {
            $this->reportStage($request->service, 'starting');
            $startResult = $client->startVm($vmName, $result['external_id'] ?? null);
            if (isset($startResult['error'])) {
                $error = (string) $startResult['error'];
                $result['warning'] = 'VM created but failed to start: '.$error;
                $result['start_error'] = $error;
            } else {
                $vmRunning = true;
            }
        }

        // Propagate guest credentials when supplied via overrides (never stored in meta).
        $guestUser = trim((string) ($request->config['guest_username'] ?? ''));
        $guestPass = trim((string) ($request->config['guest_password'] ?? ''));
        if ($guestUser !== '') {
            $result['guest_username'] = $guestUser;
        }
        if ($guestPass !== '') {
            $result['guest_password'] = $guestPass;
        }

        // Best-effort credential probe: read-only PowerShell Direct auth check
        // against the running guest. NEVER throws — the VM exists and runs, so
        // a probe failure is a loud warning, never a creation failure.
        // WHY the locals: the opt-in password rotation below must only run on
        // a verified probe, and its "did not authenticate" warning supersedes
        // the probe warning but must never overwrite a start-failure warning.
        $probeVerified = false;
        if ($guestUser !== '' && $guestPass !== '' && $vmRunning) {
            $this->reportStage($request->service, 'credentials');
            $probeAttempts = (int) ($request->config['credential_probe_attempts'] ?? 12);
            $probeDelay = (int) ($request->config['credential_probe_delay_seconds'] ?? 15);
            $probe = $client->verifyGuestAdminCredentials($vmName, $result['external_id'] ?? null, $guestUser, $guestPass, $probeAttempts, $probeDelay);
            if (($probe['verified'] ?? null) === true) {
                $result['credentials_verified'] = true;
                $probeVerified = true;
            } elseif (isset($probe['error'])) {
                $result['warning'] = 'VM created and started, but the stored Administrator credentials could not be verified: '.$probe['error'].' — check the template password or use Reset password.';
            }
        }

        // Opt-in rotation: the operator supplied the template's CURRENT
        // password and asked the panel to set a fresh one inside the running
        // guest (Create modal "apply_password"). Runs only on a verified
        // probe — the current credentials must authenticate before we change
        // them. NEVER throws: the VM exists and runs, so a rotation failure
        // is a warning, never a creation failure.
        if (! empty($request->config['apply_password'])) {
            if ($probeVerified && $guestUser !== '' && $guestPass !== '') {
                $this->reportStage($request->service, 'password');
                // Same Windows-safe convention as AbstractPanelModule::password().
                $newPassword = Str::password(16, letters: true, numbers: true, symbols: false).'!aA9';
                $rotated = $client->resetGuestAdminPassword($vmName, $result['external_id'] ?? null, $guestUser, $guestPass, $newPassword);
                if (isset($rotated['error'])) {
                    $result['warning'] = 'VM created and started, but setting a new Administrator password failed: '.(string) $rotated['error'].'. The stored credentials are unchanged — use Reset password.';
                } else {
                    // REPLACES the recorded credential via AbstractPanelModule.
                    $result['guest_password'] = $newPassword;
                    $result['guest_password_rotated'] = true;
                    $result['notice'] = 'a new Administrator password was set — view it under Credentials';
                }
            } elseif (empty($result['warning'])) {
                // A start-failure warning is preserved above; otherwise say
                // precisely why the rotation did not happen: the guest never
                // came up, or the current credentials failed to authenticate.
                $result['warning'] = $vmRunning
                    ? 'VM created and started, but a new Administrator password could not be set because the current credentials did not authenticate. Use Reset password.'
                    : 'VM created but not started, so a new Administrator password could not be set. Start the VM, then use Reset password.';
            }
        }

        return $result;
    }

    /**
     * Live probe of the recorded VM. Never throws.
     *
     * @return array{exists:bool|null,state:?string,name:?string,vmId:?string,error:?string}
     */
    public function recordedVmState(PanelAccount $account): array
    {
        try {
            $identity = $this->vmIdentityFor($account);
            $result = (new HyperVClient($account->server ?? $this->resolveServerForAccount($account)))->getVmState($identity['name'], $identity['id']);
        } catch (\Throwable $e) {
            return ['exists' => null, 'state' => null, 'name' => null, 'vmId' => null, 'error' => $e->getMessage()];
        }

        if (isset($result['error'])) {
            return ['exists' => null, 'state' => null, 'name' => null, 'vmId' => null, 'error' => (string) $result['error']];
        }

        $exists = (bool) ($result['exists'] ?? false);

        return [
            'exists' => $exists,
            'state' => isset($result['state']) ? (string) $result['state'] : null,
            'name' => $exists && isset($result['name']) && (string) $result['name'] !== '' ? (string) $result['name'] : $identity['name'],
            'vmId' => $exists && isset($result['vmId']) && (string) $result['vmId'] !== '' ? (string) $result['vmId'] : $identity['id'],
            'error' => null,
        ];
    }

    private function resolveServerForAccount(PanelAccount $account): Server
    {
        if ($account->server !== null) {
            return $account->server;
        }
        if ($account->server_id !== null) {
            $srv = Server::find($account->server_id);
            if ($srv !== null) {
                return $srv;
            }
        }
        // Fallback to service's server if available
        $service = $account->serviceInstance;
        if ($service !== null && $service->server !== null) {
            return $service->server;
        }
        throw new \RuntimeException('No server configured for Hyper-V VM.');
    }

    /**
     * Reset guest Administrator password via PowerShell Direct.
     */
    public function resetGuestAdminPassword(ServiceInstance $service, string $newPassword, ?string $username = null, ?string $currentPassword = null): ProvisioningResult
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

        $store = app(\App\Services\Provisioning\VmGuestCredentialStore::class);
        $stored = $store->read($account);

        $resolvedUsername = $username !== null && trim($username) !== '' ? trim($username) : ($stored['username'] ?? null);
        if ($resolvedUsername === null || trim($resolvedUsername) === '') {
            $resolvedUsername = 'Administrator';
        }

        $resolvedCurrent = $currentPassword !== null && $currentPassword !== '' ? $currentPassword : ($stored['password'] ?? null);

        if ($resolvedCurrent === null || $resolvedCurrent === '') {
            return ProvisioningResult::fail('No Administrator credentials are stored for this VM — enter the current password.');
        }

        try {
            $identity = $this->vmIdentityFor($account);
            $result = (new HyperVClient($service->server))->resetGuestAdminPassword($identity['name'], $identity['id'], $resolvedUsername, $resolvedCurrent, $newPassword);
        } catch (\Throwable $e) {
            return ProvisioningResult::fail('Hyper-V password reset failed: '.$e->getMessage());
        }

        if (isset($result['error'])) {
            return ProvisioningResult::fail('Hyper-V password reset failed: '.$result['error']);
        }

        try {
            $store->store($account, $resolvedUsername, $newPassword);
        } catch (\Throwable $e) {
            Log::warning('Hyper-V guest credential store failed after password reset', [
                'panel_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Keep the RDP console config (reveal / .rdp download) consistent with
        // the password that is now live inside the guest.
        try {
            $hostingAccount = $this->hostingAccountForService($service);
            if ($hostingAccount !== null) {
                $store->syncRdpConsole($hostingAccount, $resolvedUsername, $newPassword);
            }
        } catch (\Throwable $e) {
            Log::warning('Hyper-V RDP console sync failed after password reset', [
                'panel_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ProvisioningResult::ok("Hyper-V VM '{$identity['name']}' Administrator password reset", [
            'username' => $resolvedUsername,
            'password' => $newPassword,
            'vmName' => $identity['name'],
        ]);
    }

    /**
     * Hosting account behind a service (order link first, then the HOST-{id}
     * mirror tag used by the manual provisioning flow).
     */
    private function hostingAccountForService(ServiceInstance $service): ?\App\Models\HostingAccount
    {
        if ($service->order_id !== null) {
            $account = \App\Models\HostingAccount::where('order_id', $service->order_id)->first();
            if ($account !== null) {
                return $account;
            }
        }

        if (is_string($service->service_tag) && preg_match('/^HOST-(\d+)$/', $service->service_tag, $m) === 1) {
            return \App\Models\HostingAccount::find((int) $m[1]);
        }

        return null;
    }

    private function reportStage(ServiceInstance $service, string $stage): void
    {
        try {
            $event = \App\Models\ProvisioningEvent::where('service_instance_id', $service->id)
                ->where('status', 'running')
                ->orderByDesc('id')
                ->first();
            if ($event !== null) {
                app(\App\Services\Provisioning\ProvisioningEventRecorder::class)->progress($event, $stage);
            }
        } catch (\Throwable) {
            // Progress reporting must never break provisioning.
        }
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
            $identity = $this->vmIdentityFor($account);
            $vmName = $identity['name'];
            $result = (new HyperVClient($service->server))->restartVm($vmName, $identity['id']);
        } catch (PanelException $e) {
            return ProvisioningResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            return ProvisioningResult::fail('Hyperv restart failed unexpectedly: '.$e->getMessage());
        }

        if (isset($result['error'])) {
            return ProvisioningResult::fail('Hyper-V restart failed: '.$result['error']);
        }

        $this->syncVmNameDrift($account, $vmName, $result);

        return ProvisioningResult::ok("Hyper-V VM '{$vmName}' restarted", [
            'username' => $account->username,
            'external_id' => $account->external_id ?? $account->username,
            'state' => $result['state'] ?? null,
        ]);
    }

    /**
     * Rename the VM on the host. NOT part of the ProvisioningModule contract —
     * called directly (admin host_name change) following the restart() pattern.
     * Resolves the VM ID-first so a stale recorded name still finds it.
     */
    public function rename(ServiceInstance $service, string $newName): ProvisioningResult
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
            $identity = $this->vmIdentityFor($account);
            $result = (new HyperVClient($service->server))->renameVm($identity['name'], $newName, $identity['id']);
        } catch (PanelException $e) {
            return ProvisioningResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            return ProvisioningResult::fail('Hyperv rename failed unexpectedly: '.$e->getMessage());
        }

        if (isset($result['error'])) {
            return ProvisioningResult::fail('Hyper-V rename failed: '.$result['error']);
        }

        $new = (string) ($result['name'] ?? trim($newName));

        $this->writeVmNameMeta($account, $new);

        $state = null;
        try {
            $probe = (new HyperVClient($service->server))->getVmState($new, ($result['vmId'] ?? null) ?: $identity['id']);
            if (! isset($probe['error']) && ($probe['exists'] ?? false) === true) {
                $state = $probe['state'] ?? null;
            }
        } catch (\Throwable) {
            // Best-effort only — the rename already succeeded.
        }

        return ProvisioningResult::ok("Hyper-V VM renamed to '{$new}'", [
            'vmName' => $new,
            'external_id' => $account->external_id,
            'state' => $state,
        ]);
    }

    /**
     * Recorded VM identity: the host-side name plus the VM GUID when one is
     * recorded (external_id first, then the clone-result meta shapes).
     *
     * @return array{name: string, id: ?string}
     *
     * @throws PanelException when the record has no host-manageable VM name
     */
    public function vmIdentityFor(PanelAccount $account): array
    {
        return ['name' => $this->vmNameFor($account), 'id' => $this->vmIdFor($account)];
    }

    protected function suspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        // Billing suspend == graceful Stop-VM (integration-services shutdown).
        // Never -Force / -TurnOff from the panel.
        $identity = $this->vmIdentityFor($account);
        $result = (new HyperVClient($server))->stopVm($identity['name'], $identity['id']);

        if (isset($result['error'])) {
            throw new PanelException('Hyper-V stop failed: '.$result['error']);
        }

        $this->syncVmNameDrift($account, $identity['name'], $result);
    }

    protected function unsuspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        // Start-VM, idempotent when already Running.
        $identity = $this->vmIdentityFor($account);
        $result = (new HyperVClient($server))->startVm($identity['name'], $identity['id']);

        if (isset($result['error'])) {
            throw new PanelException('Hyper-V start failed: '.$result['error']);
        }

        $this->syncVmNameDrift($account, $identity['name'], $result);
    }

    protected function terminateRemote(PanelAccount $account, Server $server, array $config): void
    {
        // Remove-VM -Force + optional recorded-VHD delete (default ON, per
        // product opt-out). Refuses while Running — operator stops first.
        $identity = $this->vmIdentityFor($account);
        $deleteVhd = filter_var($config['delete_vhd_on_terminate'] ?? true, FILTER_VALIDATE_BOOL);
        $vhdPath = $this->recordedVhdPath($account);

        $result = (new HyperVClient($server))->removeVm($identity['name'], $vhdPath, $deleteVhd, $identity['id']);

        if (isset($result['error'])) {
            throw new PanelException('Hyper-V delete failed: '.$result['error']);
        }

        $this->syncVmNameDrift($account, $identity['name'], $result);
    }

    /**
     * The recorded VM GUID, or null when none is recorded (name-only lookup).
     */
    private function vmIdFor(PanelAccount $account): ?string
    {
        $external = trim((string) ($account->external_id ?? ''));

        if ($this->isGuid($external)) {
            return $external;
        }

        $meta = is_array($account->meta) ? $account->meta : [];
        $inner = is_array($meta['meta'] ?? null) ? $meta['meta'] : [];

        foreach ([$inner['vmId'] ?? null, $meta['vmId'] ?? null] as $candidate) {
            $candidate = trim((string) ($candidate ?? ''));

            if ($this->isGuid($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isGuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) === 1;
    }

    /**
     * Keep the recorded VM name in sync when the host reports a different
     * actual name (renamed outside the panel). Best-effort: never throws,
     * external_id untouched.
     *
     * @param  array<string, mixed>  $result  client result carrying the actual `name`
     */
    private function syncVmNameDrift(PanelAccount $account, string $recordedName, array $result): void
    {
        $actual = trim((string) ($result['name'] ?? ''));

        if ($actual === '' || $actual === $recordedName) {
            return;
        }

        $this->writeVmNameMeta($account, $actual);
    }

    /**
     * Persist the VM name into both meta shapes the readers accept (flat
     * `meta.vmName` and inner `meta.meta.vmName`). Best-effort, never throws.
     */
    private function writeVmNameMeta(PanelAccount $account, string $name): void
    {
        try {
            $meta = is_array($account->meta) ? $account->meta : [];
            $meta['vmName'] = $name;
            $inner = is_array($meta['meta'] ?? null) ? $meta['meta'] : [];
            $inner['vmName'] = $name;
            $meta['meta'] = $inner;
            $account->meta = $meta;
            $account->save();
        } catch (\Throwable $e) {
            Log::warning('Hyper-V VM name sync failed', [
                'panel_account_id' => $account->id,
                'vm_name' => $name,
                'error' => $e->getMessage(),
            ]);
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
