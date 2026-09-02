<?php

declare(strict_types=1);

namespace Modules\SshConsole;

use App\Contracts\Module\AbstractModule;
use App\Contracts\Module\Capabilities\HostingAccountToolsProvider;
use App\Contracts\Module\ModuleContext;
use App\Models\HostingAccount;
use App\Models\IpAddress;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Modules\SshConsole\Console\Commands\PruneSshSessions;
use Modules\SshConsole\Http\Controllers\SshConsoleController;
use Modules\SshConsole\Models\SshConsoleConfig;

/**
 * SSH Console module: provides a browser-based SSH terminal as the
 * remote-access counterpart of the RDP Console module. System monitoring
 * moved to the standalone snmp-monitor module, so this module is SSH-only.
 *
 * The terminal streams an interactive phpseclib3 shell to xterm.js through a
 * single long-running NDJSON response (see SshTerminalService).
 */
final class SshConsole extends AbstractModule implements HostingAccountToolsProvider
{
    public function boot(ModuleContext $context): void
    {
        View::addNamespace('ssh-console', dirname(__DIR__).'/resources/views');

        // Console surface: register the stale-session pruner. Modules boot via
        // ModuleManager rather than as service providers, so the command is
        // queued behind a runningInConsole guard via ConsoleApplication::starting()
        // (see SnmpMonitor module for the same pattern). Deferred until artisan
        // is building so the console app is not forced during web boot.
        if (app()->runningInConsole()) {
            ConsoleApplication::starting(function ($artisan): void {
                $artisan->add(app(PruneSshSessions::class));
            });
        }
    }

    public function registerRoutes(Router $router): void
    {
        $router->middleware(['web', 'auth', 'admin', 'permission:hosting.manage'])
            ->prefix('admin')
            ->name('admin.ssh-console.')
            ->group(function (Router $router): void {
                $router->get('hosting-accounts/{hostingAccount}/ssh-console', [SshConsoleController::class, 'edit'])
                    ->name('edit');

                $router->put('hosting-accounts/{hostingAccount}/ssh-console', [SshConsoleController::class, 'update'])
                    ->name('update');

                // Terminal lifecycle. /open is rate limited harder than the
                // keystroke relay; input is throttled generously because
                // xterm.js sends one request per keystroke burst.
                $router->post('hosting-accounts/{hostingAccount}/ssh-console/open', [SshConsoleController::class, 'open'])
                    ->middleware('throttle:10,1')
                    ->name('open');

                $router->post('hosting-accounts/{hostingAccount}/ssh-console/{token}/input', [SshConsoleController::class, 'input'])
                    ->middleware('throttle:1200,1')
                    ->name('input');

                $router->post('hosting-accounts/{hostingAccount}/ssh-console/{token}/resize', [SshConsoleController::class, 'resize'])
                    ->middleware('throttle:60,1')
                    ->name('resize');

                $router->post('hosting-accounts/{hostingAccount}/ssh-console/{token}/close', [SshConsoleController::class, 'close'])
                    ->name('close');

                $router->get('hosting-accounts/{hostingAccount}/ssh-console/{token}/stream', [SshConsoleController::class, 'stream'])
                    ->name('stream');
            });

        $router->middleware(['web', 'auth', 'admin', 'permission:hosting.view'])
            ->prefix('admin')
            ->name('admin.ssh-console.')
            ->group(function (Router $router): void {
                $router->get('hosting-accounts/{hostingAccount}/ssh-console/password', [SshConsoleController::class, 'password'])
                    ->name('password');

                $router->get('hosting-accounts/{hostingAccount}/ssh-console/html', [SshConsoleController::class, 'html'])
                    ->name('html');
            });
    }

    public function hostingAccountTools(HostingAccount $account, array $config): ?array
    {
        // Per-account SSH config (ssh_console_configs) for the web terminal,
        // loaded here so core never imports module models. Guarded like the
        // pre-decoupling controller load: an un-migrated table degrades to
        // "not configured".
        try {
            $sshConfig = SshConsoleConfig::query()
                ->where('hosting_account_id', $account->id)
                ->first();
        } catch (\Throwable) {
            $sshConfig = null;
        }

        return [
            'view' => 'ssh-console::tools',
            'data' => [
                'hostingAccount' => $account,
                'sshConfig' => $sshConfig,
                'assignedIps' => $this->assignedIps($account),
            ],
        ];
    }

    /**
     * IP leases for the account's host dropdown / effective-host fallback.
     * Same shape the core show page renders its own IP tables from; only the
     * subnet VLAN chain is needed here.
     */
    private function assignedIps(HostingAccount $account): Collection
    {
        return IpAddress::query()
            ->where('assigned_to_type', HostingAccount::class)
            ->where('assigned_to_id', $account->id)
            ->with(['subnet.vlan'])
            ->orderBy('id')
            ->get();
    }
}
