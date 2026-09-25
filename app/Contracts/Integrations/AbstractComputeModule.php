<?php

declare(strict_types=1);

namespace App\Contracts\Integrations;

use App\Contracts\Integrations\Capabilities\ProvisioningModule;
use App\Models\PanelAccount;
use App\Models\Server;
use App\Models\ServiceInstance;
use Illuminate\Support\Str;
use Throwable;

/**
 * Base for compute / virtualization provisioning modules (Hyper-V, Proxmox
 * and future drivers, Virtualizor when refactored).
 *
 * Mirrors AbstractPanelModule's lifecycle — username/password derivation,
 * unusable guard, PanelException -> ProvisioningResult::fail isolation,
 * idempotent provision when an active panel_account already exists — but
 * keeps its own panel discriminator and server-config checks so it does not
 * inherit hosting-specific defaults (e.g. requiresDomain).
 *
 * Also satisfies TestableServerModule: concrete compute modules override
 * serverConfigSchema()/testConnection()/getServerInfo(); stubs here keep
 * the class instantiable without a live hypervisor.
 */
abstract class AbstractComputeModule implements ProvisioningModule, TestableServerModule
{
    protected const USERNAME_MAX = 16;

    protected const RESERVED_USERNAMES = ['root', 'test', 'admin', 'mysql', 'nobody', 'cpanel', 'plesk'];

    public function configSchema(): array
    {
        return ['fields' => []];
    }

    /** Discriminator stored in panel_accounts.panel for this driver. */
    abstract protected function panel(): string;

    /** @return array<string, mixed> */
    abstract protected function createRemote(PanelProvisionRequest $request): array;

    /** @throws PanelException */
    abstract protected function suspendRemote(PanelAccount $account, Server $server, array $config): void;

    /** @throws PanelException */
    abstract protected function unsuspendRemote(PanelAccount $account, Server $server, array $config): void;

    /** @throws PanelException */
    abstract protected function terminateRemote(PanelAccount $account, Server $server, array $config): void;

    protected function requiresDomain(): bool
    {
        return false;
    }

    abstract protected function serverIsConfigured(?Server $server): bool;

    protected function credentialHint(): string
    {
        return 'set api_url, api_username and api_key on the server';
    }

    // ───────────────────── TestableServerModule defaults ─────────────────────

    public function serverConfigSchema(): array
    {
        return ['fields' => []];
    }

    public function testConnection(Server $server): ServerConnectionResult
    {
        return ServerConnectionResult::fail('Test connection not implemented for '.$this->panel().'.');
    }

    public function getServerInfo(Server $server): ServerInfoDTO
    {
        return new ServerInfoDTO(
            hostname: (string) ($server->api_url ?: $server->ip_address),
            version: '',
            ipAddress: (string) $server->ip_address,
            totalAccounts: 0,
            latencyMs: 0,
            meta: [],
        );
    }

    // ───────────────────── ProvisioningModule ─────────────────────

    public function provision(ServiceInstance $service, array $config): ProvisioningResult
    {
        $existing = $this->accountFor($service);

        if ($existing !== null && $existing->status === PanelAccount::STATUS_ACTIVE) {
            return ProvisioningResult::ok(ucfirst($this->panel()).' resource already provisioned', [
                'username' => $existing->username,
                'external_id' => $existing->external_id ?? $existing->username,
            ]);
        }

        $guard = $this->unusable($service);

        if ($guard !== null) {
            return ProvisioningResult::fail($guard);
        }

        $domain = trim((string) $service->domain);

        if ($domain === '' && $this->requiresDomain()) {
            return ProvisioningResult::fail(ucfirst($this->panel()).' needs a domain and this service has none.');
        }

        $request = new PanelProvisionRequest(
            service: $service,
            server: $service->server,
            config: $config,
            username: $this->username($service, $domain),
            password: $this->password(),
            domain: $domain,
            contactEmail: $this->contactEmail($service, $config),
            plan: trim((string) ($config['plan'] ?? '')),
        );

        try {
            $data = $this->createRemote($request);
        } catch (PanelException $e) {
            return ProvisioningResult::fail($e->getMessage());
        } catch (Throwable $e) {
            return ProvisioningResult::fail(sprintf(
                '%s provisioning failed unexpectedly: %s',
                ucfirst($this->panel()),
                $e->getMessage(),
            ));
        }

        $metaData = $data;
        $guestUsername = null;
        $guestPassword = null;
        $warning = null;
        $notice = null;
        if (is_array($metaData)) {
            // Guest credentials are persisted to their own encrypted columns —
            // never in `meta`. `warning`/`notice` belong in the human message,
            // not the meta (mirrors AbstractPanelModule).
            if (array_key_exists('guest_username', $metaData)) {
                $guestUsername = $metaData['guest_username'];
                unset($metaData['guest_username']);
            }
            if (array_key_exists('guest_password', $metaData)) {
                $guestPassword = $metaData['guest_password'];
                unset($metaData['guest_password']);
            }
            if (array_key_exists('warning', $metaData)) {
                $warning = $metaData['warning'];
                unset($metaData['warning']);
            }
            if (array_key_exists('notice', $metaData)) {
                $notice = $metaData['notice'];
                unset($metaData['notice']);
            }
        }

        $panelData = [
            'server_id' => $service->server_id,
            'panel' => $this->panel(),
            'username' => $request->username,
            'domain' => $domain !== '' ? $domain : null,
            'password_encrypted' => $request->password,
            'plan' => $request->plan !== '' ? $request->plan : null,
            'external_id' => isset($data['external_id']) ? (string) $data['external_id'] : null,
            'meta' => $metaData === [] || $metaData === null ? null : $metaData,
            'status' => PanelAccount::STATUS_ACTIVE,
            'provisioned_at' => now(),
            'suspended_at' => null,
            'terminated_at' => null,
        ];
        if (is_string($guestUsername) && trim($guestUsername) !== '') {
            $panelData['guest_username'] = trim($guestUsername);
        }
        if (is_string($guestPassword) && trim($guestPassword) !== '') {
            $panelData['guest_password_encrypted'] = trim($guestPassword);
        }

        PanelAccount::updateOrCreate(
            ['service_instance_id' => $service->id],
            $panelData,
        );

        $message = ucfirst($this->panel()).' resource created';
        if (is_string($warning) && trim($warning) !== '') {
            $message .= ' — '.trim($warning);
        }
        if (is_string($notice) && trim($notice) !== '') {
            $message .= ' — '.trim($notice);
        }

        return ProvisioningResult::ok($message, array_filter([
            'username' => $request->username,
            'external_id' => $data['external_id'] ?? $request->username,
            'password' => $request->password,
            'guest_username' => $guestUsername,
            'guest_password' => $guestPassword,
            'ip' => $data['ip'] ?? $service->server?->ip_address,
        ], static fn ($v) => $v !== null));
    }

    public function suspend(ServiceInstance $service, array $config): ProvisioningResult
    {
        return $this->lifecycle($service, $config, PanelAccount::STATUS_SUSPENDED, 'suspended',
            fn (PanelAccount $a, Server $s) => $this->suspendRemote($a, $s, $config));
    }

    public function unsuspend(ServiceInstance $service, array $config): ProvisioningResult
    {
        return $this->lifecycle($service, $config, PanelAccount::STATUS_ACTIVE, 'unsuspended',
            fn (PanelAccount $a, Server $s) => $this->unsuspendRemote($a, $s, $config));
    }

    public function terminate(ServiceInstance $service, array $config): ProvisioningResult
    {
        return $this->lifecycle($service, $config, PanelAccount::STATUS_TERMINATED, 'terminated',
            fn (PanelAccount $a, Server $s) => $this->terminateRemote($a, $s, $config));
    }

    // ───────────────────── shared internals ─────────────────────

    /**
     * @param  array<string, mixed>  $config
     * @param  callable(PanelAccount, Server): void  $call
     */
    private function lifecycle(
        ServiceInstance $service,
        array $config,
        string $status,
        string $verb,
        callable $call,
    ): ProvisioningResult {
        $account = $this->accountFor($service);

        if ($account === null) {
            return ProvisioningResult::fail('No '.$this->panel().' resource is recorded for this service.');
        }

        $guard = $this->unusable($service);

        if ($guard !== null) {
            return ProvisioningResult::fail($guard);
        }

        try {
            $call($account, $service->server);
        } catch (PanelException $e) {
            return ProvisioningResult::fail($e->getMessage());
        } catch (Throwable $e) {
            return ProvisioningResult::fail(sprintf('%s %s failed unexpectedly: %s', ucfirst($this->panel()), $verb, $e->getMessage()));
        }

        $account->update([
            'status' => $status,
            'suspended_at' => $status === PanelAccount::STATUS_SUSPENDED ? now() : null,
            'terminated_at' => $status === PanelAccount::STATUS_TERMINATED ? now() : null,
        ]);

        return ProvisioningResult::ok(ucfirst($this->panel())." resource {$verb}", ['username' => $account->username]);
    }

    protected function accountFor(ServiceInstance $service): ?PanelAccount
    {
        return PanelAccount::where('service_instance_id', $service->id)
            ->where('panel', $this->panel())
            ->first();
    }

    private function unusable(ServiceInstance $service): ?string
    {
        if ($service->server_id === null) {
            return sprintf(
                'No server is allocated to this service — check the product\'s server group has an active %s server with capacity.',
                $this->panel(),
            );
        }

        if (! $this->serverIsConfigured($service->server)) {
            return sprintf(
                'Server "%s" is not configured for %s (%s).',
                $service->server?->name ?? $service->server_id,
                $this->panel(),
                $this->credentialHint(),
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function contactEmail(ServiceInstance $service, array $config): string
    {
        $override = trim((string) ($config['contact_email'] ?? ''));

        if ($override !== '') {
            return $override;
        }

        $email = $service->customer?->user?->email;

        return (string) ($email ?: 'postmaster@'.($service->domain ?: 'localhost'));
    }

    protected function username(ServiceInstance $service, string $domain): string
    {
        $suffix = (string) $service->id;
        $source = $domain !== '' ? Str::before($domain, '.') : (string) $service->username;
        $base = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $source) ?? '');

        if ($base === '' || ctype_digit($base[0]) || in_array($base, static::RESERVED_USERNAMES, true)) {
            $base = 'u'.$base;
        }

        $base = substr($base, 0, max(1, static::USERNAME_MAX - strlen($suffix)));

        return $base.$suffix;
    }

    protected function password(): string
    {
        return Str::password(20, letters: true, numbers: true, symbols: false).'!aA9';
    }
}
