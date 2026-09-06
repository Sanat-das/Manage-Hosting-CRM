<?php

declare(strict_types=1);

namespace Modules\Cpanel;

use App\Contracts\Module\AbstractPanelModule;
use App\Contracts\Module\PanelProvisionRequest;
use App\Models\PanelAccount;
use App\Models\Server;
use Modules\Cpanel\Services\WhmClient;

/**
 * cPanel/WHM provisioning module.
 *
 * Turns an order that reached `provisioning` into a real cPanel account on the
 * WHM server the order was allocated to (see App\Services\Provisioning\
 * ServerAllocator), and services the rest of the lifecycle.
 *
 * Credentials are NOT module config: they live on the Server row
 * (`api_username` + `api_key`, a WHM API token), so one installed module serves
 * a whole estate. What IS per-product is the WHM package to create the account
 * under, which is why `plan` is on the config schema.
 *
 * All the lifecycle bookkeeping lives in AbstractPanelModule; this class is
 * just the WHM API 1 calls.
 */
final class Cpanel extends AbstractPanelModule
{
    public function configSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'plan', 'label' => 'WHM package', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'contact_email', 'label' => 'Contact email override', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'verify_tls', 'label' => 'Verify the WHM TLS certificate', 'type' => 'checkbox', 'default' => true],
            ],
        ];
    }

    protected function panel(): string
    {
        return 'cpanel';
    }

    protected function serverIsConfigured(?Server $server): bool
    {
        return WhmClient::isConfigured($server);
    }

    protected function credentialHint(): string
    {
        return 'set api_username and api_key (a WHM API token) on the server';
    }

    protected function createRemote(PanelProvisionRequest $request): array
    {
        $params = [
            'username' => $request->username,
            'domain' => $request->domain,
            'password' => $request->password,
            'contactemail' => $request->contactEmail,
        ];

        if ($request->plan !== '') {
            // Omitted entirely when blank so WHM applies its own default
            // package rather than failing on an empty plan name.
            $params['plan'] = $request->plan;
        }

        $data = $this->client($request->server, $request->config)->call('createacct', $params);

        return array_filter([
            'external_id' => $request->username,
            'ip' => $data['ip'] ?? null,
            'nameservers' => $data['nameserver'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    protected function suspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        $this->client($server, $config)->call('suspendacct', [
            'user' => $account->username,
            'reason' => (string) ($account->serviceInstance?->suspension_reason ?: 'Suspended by billing'),
        ]);
    }

    protected function unsuspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        $this->client($server, $config)->call('unsuspendacct', ['user' => $account->username]);
    }

    protected function terminateRemote(PanelAccount $account, Server $server, array $config): void
    {
        // keepdns=0: release the DNS zone with the account, matching what the
        // order lifecycle means by "terminated".
        $this->client($server, $config)->call('removeacct', [
            'user' => $account->username,
            'keepdns' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function client(Server $server, array $config): WhmClient
    {
        return new WhmClient(
            $server,
            // Default to verifying: an estate with self-signed WHM certs opts
            // out per product rather than the module shipping insecure.
            verifyTls: filter_var($config['verify_tls'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }
}
