<?php

declare(strict_types=1);

namespace Modules\DirectAdmin;

use App\Contracts\Module\AbstractPanelModule;
use App\Contracts\Module\PanelProvisionRequest;
use App\Models\PanelAccount;
use App\Models\Server;
use Modules\DirectAdmin\Services\DirectAdminClient;

/**
 * DirectAdmin provisioning module.
 *
 * Accounts are created with `CMD_API_ACCOUNT_USER` under an admin or reseller
 * login. Suspend / unsuspend / delete all go through `CMD_API_SELECT_USERS`,
 * which takes an indexed `select0..n` list of usernames plus the action.
 *
 * DirectAdmin usernames are stricter than the other panels: 4-10 characters on
 * a default install, lowercase alphanumeric, starting with a letter. The base
 * class caps at 16, so USERNAME_MAX is narrowed here.
 */
final class DirectAdmin extends AbstractPanelModule
{
    /** DirectAdmin rejects usernames longer than 10 characters by default. */
    protected const USERNAME_MAX = 10;

    public function configSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'plan', 'label' => 'Package name', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'ip', 'label' => 'IP to assign (blank = server shared IP)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'contact_email', 'label' => 'Contact email override', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'verify_tls', 'label' => 'Verify the DirectAdmin TLS certificate', 'type' => 'checkbox', 'default' => true],
            ],
        ];
    }

    protected function panel(): string
    {
        return 'directadmin';
    }

    protected function serverIsConfigured(?Server $server): bool
    {
        return DirectAdminClient::isConfigured($server);
    }

    protected function credentialHint(): string
    {
        return 'set api_username and api_key (a DirectAdmin login key) on the server';
    }

    protected function createRemote(PanelProvisionRequest $request): array
    {
        $params = [
            'action' => 'create',
            'add' => 'Submit',
            'username' => $request->username,
            'email' => $request->contactEmail,
            'passwd' => $request->password,
            'passwd2' => $request->password,
            'domain' => $request->domain,
            // Blank means "use the server's shared IP", which is what
            // DirectAdmin does with ip=shared.
            'ip' => $request->config('ip', 'shared'),
            'notify' => 'no',
        ];

        if ($request->plan !== '') {
            $params['package'] = $request->plan;
        }

        $this->client($request->server, $request->config)->call('CMD_API_ACCOUNT_USER', $params);

        return array_filter([
            'external_id' => $request->username,
            'ip' => $request->server->ip_address,
        ], static fn ($v) => $v !== null);
    }

    protected function suspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        $this->selectUsers($account, $server, $config, ['suspend' => 'Suspend']);
    }

    protected function unsuspendRemote(PanelAccount $account, Server $server, array $config): void
    {
        $this->selectUsers($account, $server, $config, ['suspend' => 'Unsuspend']);
    }

    protected function terminateRemote(PanelAccount $account, Server $server, array $config): void
    {
        $this->selectUsers($account, $server, $config, ['delete' => 'yes', 'confirmed' => 'Confirm']);
    }

    /**
     * CMD_API_SELECT_USERS addresses users by an indexed `select0..n` list;
     * one account per call here, so always `select0`.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, scalar>  $action
     */
    private function selectUsers(PanelAccount $account, Server $server, array $config, array $action): void
    {
        $this->client($server, $config)->call('CMD_API_SELECT_USERS', $action + [
            'location' => 'CMD_SELECT_USERS',
            'select0' => $account->username,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function client(Server $server, array $config): DirectAdminClient
    {
        return new DirectAdminClient(
            $server,
            verifyTls: filter_var($config['verify_tls'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }
}
