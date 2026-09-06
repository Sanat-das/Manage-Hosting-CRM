<?php

declare(strict_types=1);

namespace Modules\Plesk;

use App\Contracts\Module\AbstractPanelModule;
use App\Contracts\Module\PanelException;
use App\Contracts\Module\PanelProvisionRequest;
use App\Models\PanelAccount;
use App\Models\Server;
use Modules\Plesk\Services\PleskClient;

/**
 * Plesk provisioning module.
 *
 * Plesk models hosting as a *subscription* owned by a *customer*, so creating
 * one is two calls: `POST /clients` then `POST /domains` with that client's id
 * and the service plan. cPanel's single `createacct` has no direct equivalent.
 *
 * Suspend / unsuspend / remove are not first-class REST v2 endpoints, so they
 * go through the CLI gateway running the `subscription` utility — see
 * PleskClient::cli().
 */
final class Plesk extends AbstractPanelModule
{
    public function configSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'plan', 'label' => 'Service plan name', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'contact_email', 'label' => 'Contact email override', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'verify_tls', 'label' => 'Verify the Plesk TLS certificate', 'type' => 'checkbox', 'default' => true],
            ],
        ];
    }

    protected function panel(): string
    {
        return 'plesk';
    }

    protected function serverIsConfigured(?Server $server): bool
    {
        return PleskClient::isConfigured($server);
    }

    protected function credentialHint(): string
    {
        return 'set api_key to a Plesk API key, or api_username + api_key for admin basic auth';
    }

    protected function createRemote(PanelProvisionRequest $request): array
    {
        $client = $this->client($request->server, $request->config);

        // 1. The owning client. Plesk requires a login and a contact name; the
        //    generated username serves as both so one order maps to one client.
        $owner = $client->post('/clients', [
            'name' => $request->contactEmail,
            'login' => $request->username,
            'password' => $request->password,
            'email' => $request->contactEmail,
        ]);

        $ownerId = $owner['id'] ?? null;

        if ($ownerId === null) {
            throw new PanelException('Plesk created a client but returned no id.');
        }

        // 2. The subscription itself. `plan` is optional: without it Plesk
        //    creates the domain on the default plan.
        $payload = [
            'name' => $request->domain,
            'hosting_type' => 'virtual',
            'owner_client' => ['id' => $ownerId],
            'hosting_settings' => [
                'ftp_login' => $request->username,
                'ftp_password' => $request->password,
            ],
        ];

        if ($request->plan !== '') {
            $payload['plan'] = ['name' => $request->plan];
        }

        $domain = $client->post('/domains', $payload);

        return array_filter([
            // The subscription id is what the CLI utility acts on later.
            'external_id' => $domain['id'] ?? null,
            'client_id' => $ownerId,
            'ip' => $request->server->ip_address,
        ], static fn ($v) => $v !== null);
    }

    protected function suspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        $this->client($server, $config)->cli('subscription', ['--suspend', $this->subscriptionName($account)]);
    }

    protected function unsuspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        $this->client($server, $config)->cli('subscription', ['--activate', $this->subscriptionName($account)]);
    }

    protected function terminateRemote(PanelAccount $account, Server $server, array $config): void
    {
        $this->client($server, $config)->cli('subscription', ['--remove', $this->subscriptionName($account)]);
    }

    /**
     * The `subscription` utility addresses a subscription by its domain name,
     * not the numeric id the REST API returns.
     */
    private function subscriptionName(PanelAccount $account): string
    {
        return (string) $account->domain;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function client(Server $server, array $config): PleskClient
    {
        return new PleskClient(
            $server,
            verifyTls: filter_var($config['verify_tls'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }
}
