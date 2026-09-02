<?php

declare(strict_types=1);

namespace Modules\RdpConsole;

use App\Contracts\Module\AbstractModule;
use App\Contracts\Module\Capabilities\HostingAccountToolsProvider;
use App\Contracts\Module\ModuleContext;
use App\Models\HostingAccount;
use App\Models\IpAddress;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Modules\RdpConsole\Http\Controllers\RdpConsoleController;
use Modules\RdpConsole\Models\RdpConsoleConfig;
use Modules\RdpConsole\Services\Gateway\GatewayDriver;
use Modules\RdpConsole\Services\Gateway\GuacamoleLiteDriver;

/**
 * RDP Console module: manages per-account RDP connection settings (host,
 * port, credentials) for a Windows host and serves them to admins via the
 * .rdp download, native rdp:// launch, password reveal and HTML console
 * endpoints. SNMP monitoring moved to the dedicated snmp-monitor module.
 *
 * Routes, the RDP config model/migration and views are provided by the
 * module's controller and resources/views directory.
 */
final class RdpConsole extends AbstractModule implements HostingAccountToolsProvider
{
    public function boot(ModuleContext $context): void
    {
        View::addNamespace('rdp-console', dirname(__DIR__).'/resources/views');

        // The guacamole-lite driver reads its secret/ws URL from
        // config('rdp-console.*') at mint time, so a lazy factory binding
        // is enough — no per-request state.
        app()->bind(GatewayDriver::class, static fn (): GatewayDriver => new GuacamoleLiteDriver);
    }

    public function registerRoutes(Router $router): void
    {
        $router->middleware(['web', 'auth', 'admin', 'permission:hosting.manage'])
            ->prefix('admin')
            ->name('admin.rdp-console.')
            ->group(function (Router $router): void {
                $router->get('hosting-accounts/{hostingAccount}/rdp-console', [RdpConsoleController::class, 'rdpEdit'])
                    ->name('edit');

                $router->put('hosting-accounts/{hostingAccount}/rdp-console', [RdpConsoleController::class, 'rdpUpdate'])
                    ->name('update');
            });

        $router->middleware(['web', 'auth', 'admin', 'permission:hosting.view'])
            ->prefix('admin')
            ->name('admin.rdp-console.')
            ->group(function (Router $router): void {
                $router->get('hosting-accounts/{hostingAccount}/rdp-console/password', [RdpConsoleController::class, 'rdpPassword'])
                    ->name('password');

                $router->get('hosting-accounts/{hostingAccount}/rdp-console/token', [RdpConsoleController::class, 'rdpToken'])
                    ->name('token');

                $router->get('hosting-accounts/{hostingAccount}/rdp-console/download', [RdpConsoleController::class, 'rdpDownload'])
                    ->name('download');

                $router->get('hosting-accounts/{hostingAccount}/rdp-console/html', [RdpConsoleController::class, 'rdpHtml'])
                    ->name('html');

                // Serves the vendored guacamole-common-js client from the
                // module's own resources — the URL ends in the exact file name.
                $router->get('hosting-accounts/{hostingAccount}/rdp-console/guacamole-common.min.js', [RdpConsoleController::class, 'rdpClientAsset'])
                    ->name('clientAsset');
            });
    }

    public function configSchema(): array
    {
        // SNMP product-config fields moved to the snmp-monitor module; this
        // module is RDP-only and configures per-account RDP settings instead.
        return ['fields' => []];
    }

    public function hostingAccountTools(HostingAccount $account, array $config): ?array
    {
        // Per-account RDP config (rdp_console_configs), loaded here so
        // core never imports module models. Guarded like the pre-decoupling
        // controller load: an un-migrated table degrades to "not configured".
        try {
            $rdpConfig = RdpConsoleConfig::query()
                ->where('hosting_account_id', $account->id)
                ->first();
        } catch (\Throwable) {
            $rdpConfig = null;
        }

        return [
            'view' => 'rdp-console::tools',
            'data' => [
                'hostingAccount' => $account,
                'rdpConfig' => $rdpConfig,
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
