<?php

declare(strict_types=1);

namespace App\Contracts\Module;

use App\Contracts\Module\Capabilities\ProvisioningModule;
use App\Models\PanelAccount;
use App\Models\Server;
use App\Models\ServiceInstance;
use Illuminate\Support\Str;
use Throwable;

/**
 * Base for every control-panel provisioning module (cPanel, Plesk,
 * DirectAdmin, Virtualizor).
 *
 * The lifecycle around a panel call is identical for all of them — check the
 * server is usable, derive a legal username, generate a password, call the
 * panel, record the result, isolate any failure — and only the API call itself
 * differs. Subclasses implement the four `*Remote()` methods and declare their
 * panel slug; everything else lives here.
 *
 * Contract for subclasses: the `*Remote()` methods signal failure by throwing
 * PanelException. Nothing else may escape — this class converts both that and
 * any unexpected Throwable into a ProvisioningResult::fail(), because an
 * exception reaching ProvisioningDispatcher would be a failed order with a
 * stack trace instead of the panel's actual reason.
 */
abstract class AbstractPanelModule extends AbstractModule implements ProvisioningModule
{
    /** Panel usernames are commonly capped at 16; the shortest wins. */
    protected const USERNAME_MAX = 16;

    /** Names panels routinely refuse. */
    protected const RESERVED_USERNAMES = ['root', 'test', 'admin', 'mysql', 'nobody', 'cpanel', 'plesk'];

    /** The `panel_accounts.panel` discriminator, e.g. 'cpanel'. */
    abstract protected function panel(): string;

    /**
     * Create the account on the panel.
     *
     * @return array<string, mixed> provider data (external_id, ip, nameservers …)
     *
     * @throws PanelException when the panel refuses
     */
    abstract protected function createRemote(PanelProvisionRequest $request): array;

    /** @throws PanelException */
    abstract protected function suspendRemote(PanelAccount $account, Server $server, array $config): void;

    /** @throws PanelException */
    abstract protected function unsuspendRemote(PanelAccount $account, Server $server, array $config): void;

    /** @throws PanelException */
    abstract protected function terminateRemote(PanelAccount $account, Server $server, array $config): void;

    /** Whether a domain is required. VPS panels override this to false. */
    protected function requiresDomain(): bool
    {
        return true;
    }

    /** Whether the server row carries enough detail to be called. */
    abstract protected function serverIsConfigured(?Server $server): bool;

    /** Human-readable hint naming the fields a misconfigured server is missing. */
    protected function credentialHint(): string
    {
        return 'set api_username and api_key on the server';
    }

    // ─────────────────────────── ProvisioningModule ───────────────────────────

    public function provision(ServiceInstance $service, array $config): ProvisioningResult
    {
        $existing = $this->accountFor($service);

        // Already live — a retry after a partial failure must not create the
        // account twice; the panel would reject it and the retry would look
        // like a hard failure.
        if ($existing !== null && $existing->status === PanelAccount::STATUS_ACTIVE) {
            return ProvisioningResult::ok(ucfirst($this->panel()).' account already provisioned', [
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
            // A driver bug must not surface as a 500 in the payment path.
            return ProvisioningResult::fail(sprintf(
                '%s provisioning failed unexpectedly: %s',
                ucfirst($this->panel()),
                $e->getMessage(),
            ));
        }

        PanelAccount::updateOrCreate(
            ['service_instance_id' => $service->id],
            [
                'server_id' => $service->server_id,
                'panel' => $this->panel(),
                'username' => $request->username,
                'domain' => $domain !== '' ? $domain : null,
                'password_encrypted' => $request->password,
                'plan' => $request->plan !== '' ? $request->plan : null,
                'external_id' => isset($data['external_id']) ? (string) $data['external_id'] : null,
                'meta' => $data === [] ? null : $data,
                'status' => PanelAccount::STATUS_ACTIVE,
                'provisioned_at' => now(),
                'suspended_at' => null,
                'terminated_at' => null,
            ],
        );

        // The password is returned so the caller can deliver it to the
        // customer. ProvisioningDispatcher redacts it before the audit row is
        // written, so it is never persisted to provisioning_events.
        return ProvisioningResult::ok(ucfirst($this->panel()).' account created', array_filter([
            'username' => $request->username,
            'external_id' => $data['external_id'] ?? $request->username,
            'password' => $request->password,
            'ip' => $data['ip'] ?? $service->server?->ip_address,
            'nameservers' => $data['nameservers'] ?? null,
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

    // ─────────────────────────── shared internals ───────────────────────────

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
            return ProvisioningResult::fail('No '.$this->panel().' account is recorded for this service.');
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

        return ProvisioningResult::ok(ucfirst($this->panel())." account {$verb}", ['username' => $account->username]);
    }

    protected function accountFor(ServiceInstance $service): ?PanelAccount
    {
        return PanelAccount::where('service_instance_id', $service->id)
            ->where('panel', $this->panel())
            ->first();
    }

    /**
     * Why this service cannot be worked on, or null when it can be.
     */
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

    /**
     * A panel-legal username derived from the domain, made unique by the
     * service id.
     *
     * The id suffix is what guarantees uniqueness across an estate — two
     * customers ordering example.com and example.net would otherwise both want
     * "example". Reserved and digit-leading names are prefixed rather than
     * rejected so provisioning never dead-ends on a legal domain.
     */
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

    /**
     * A generated account password.
     *
     * Symbols are limited to a fixed suffix rather than the full set: panels
     * and their form/XML encodings are inconsistent about which are safe, and
     * length plus mixed classes carries the strength anyway.
     */
    protected function password(): string
    {
        return Str::password(20, letters: true, numbers: true, symbols: false).'!aA9';
    }
}
