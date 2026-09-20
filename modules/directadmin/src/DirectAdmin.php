<?php

declare(strict_types=1);

namespace Modules\DirectAdmin;

use App\Contracts\Module\AbstractPanelModule;
use App\Contracts\Module\PanelException;
use App\Contracts\Module\PanelProvisionRequest;
use App\Contracts\Module\ServerConnectionResult;
use App\Contracts\Module\ServerInfoDTO;
use App\Contracts\Module\TestableServerModule;
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
final class DirectAdmin extends AbstractPanelModule implements TestableServerModule
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

    public function serverConfigSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'api_url', 'label' => 'DirectAdmin URL (e.g. https://da.example.net:2222)', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'api_username', 'label' => 'DirectAdmin username (admin/reseller)', 'type' => 'text', 'required' => true],
                ['key' => 'api_key', 'label' => 'DirectAdmin login key', 'type' => 'password', 'required' => true, 'encrypted' => true],
                ['key' => 'verify_tls', 'label' => 'Verify DirectAdmin TLS certificate', 'type' => 'checkbox', 'default' => true],
            ],
        ];
    }

    public function testConnection(Server $server): ServerConnectionResult
    {
        $start = (int) (microtime(true) * 1000);

        try {
            $data = $this->serverClient($server)->call('CMD_API_SHOW_ALL_USERS');
            $latency = (int) (microtime(true) * 1000) - $start;

            // CMD_API_SHOW_ALL_USERS returns list of users on success.
            $userCount = 0;
            foreach ($data as $k => $v) {
                if (str_starts_with((string) $k, 'list') || is_array($v)) {
                    $userCount = is_array($v) ? count($v) : 0;
                    break;
                }
            }
            // Fallback: count keys excluding error/text/details
            if ($userCount === 0) {
                $filtered = array_filter($data, fn ($k) => ! in_array($k, ['error', 'text', 'details'], true), ARRAY_FILTER_USE_KEY);
                $userCount = count($filtered);
            }

            return ServerConnectionResult::ok(
                message: 'Connected to DirectAdmin',
                latencyMs: $latency,
                // Todo 13: error-only raw — the user list never persists;
                // the extracted count + provenance do.
                meta: static::capMeta(array_merge(
                    ['user_count' => $userCount],
                    static::successProvenance('CMD_API_SHOW_ALL_USERS'),
                )),
            );
        } catch (PanelException $e) {
            $latency = (int) (microtime(true) * 1000) - $start;

            return ServerConnectionResult::fail($e->getMessage(), $latency, static::errorMeta($e->getMessage()));
        }
    }

    public function getServerInfo(Server $server): ServerInfoDTO
    {
        $start = (int) (microtime(true) * 1000);

        try {
            $data = $this->serverClient($server)->call('CMD_API_SHOW_ALL_USERS');
            $latency = (int) (microtime(true) * 1000) - $start;

            $users = array_filter($data, fn ($k) => ! in_array($k, ['error', 'text', 'details'], true), ARRAY_FILTER_USE_KEY);
            // DirectAdmin returns user list as keys mapping to domains; count them.
            $totalAccounts = 0;
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    $totalAccounts = count($v);
                    break;
                }
            }
            if ($totalAccounts === 0 && $users !== []) {
                $totalAccounts = count($users);
            }

            // Todo 13: error-only raw — totals ride the DTO top level;
            // the user list never persists on success.
            $origin = trim((string) ($server->api_url ?: $server->ip_address));

            return new ServerInfoDTO(
                hostname: (string) ($server->api_url ?: $server->ip_address),
                version: '',
                ipAddress: (string) $server->ip_address,
                totalAccounts: $totalAccounts,
                latencyMs: $latency,
                meta: static::successProvenance(
                    'CMD_API_SHOW_ALL_USERS',
                    null,
                    $origin !== '' ? $origin : null,
                ),
            );
        } catch (PanelException $e) {
            $latency = (int) (microtime(true) * 1000) - $start;

            return new ServerInfoDTO(
                hostname: (string) ($server->api_url ?: $server->ip_address),
                version: '',
                ipAddress: (string) $server->ip_address,
                totalAccounts: 0,
                latencyMs: $latency,
                meta: static::errorMeta($e->getMessage()),
            );
        }
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

    private function serverClient(Server $server): DirectAdminClient
    {
        return new DirectAdminClient($server, verifyTls: true);
    }
}
