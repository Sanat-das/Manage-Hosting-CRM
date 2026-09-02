<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor;

use App\Contracts\Module\AbstractModule;
use App\Contracts\Module\Capabilities\HostingAccountInfoProvider;
use App\Contracts\Module\ModuleContext;
use App\Models\HostingAccount;
use App\Services\Modules\ModuleManager;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\View;
use Modules\SnmpMonitor\Console\MaintainSnmpPartitions;
use Modules\SnmpMonitor\Http\Controllers\DashboardController;
use Modules\SnmpMonitor\Http\Controllers\PollController;
use Modules\SnmpMonitor\Http\Controllers\TargetHostController;
use Modules\SnmpMonitor\Services\SnmpMetricRepository;
use Modules\SnmpMonitor\Services\TargetService;

/**
 * SNMP Monitor module: standalone monitoring with centralized collector,
 * time-series storage and admin dashboard.
 *
 * The collector, targets, dashboard, and polling pipeline are provided
 * by the module's services, controllers and resources/views directory.
 */
final class SnmpMonitor extends AbstractModule implements HostingAccountInfoProvider
{
    public function boot(ModuleContext $context): void
    {
        View::addNamespace('snmp-monitor', dirname(__DIR__).'/resources/views');

        // Console-only surface: modules boot through the module manager rather
        // than as service providers, so the command is queued here behind a
        // runningInConsole guard (it never exists in web requests).
        // ConsoleApplication::starting() defers the add to artisan build time —
        // resolving the kernel directly here would force-build the console app
        // before the framework attached its own commands.
        if (app()->runningInConsole()) {
            ConsoleApplication::starting(function ($artisan): void {
                $artisan->add(app(MaintainSnmpPartitions::class));
            });
        }
    }

    public function registerRoutes(Router $router): void
    {
        $router->middleware(['web', 'auth', 'admin', 'permission:hosting.view'])
            ->prefix('admin')
            ->name('admin.snmp-monitor.')
            ->group(function (Router $router): void {
                $router->get('snmp-monitor', [DashboardController::class, 'index'])
                    ->name('dashboard');

                // Polled by the listing page's auto-refresh toggle; read-only,
                // same guard/filters as the index action above.
                $router->get('snmp-monitor/refresh', [DashboardController::class, 'refresh'])
                    ->name('dashboard.refresh');

                // {hostingAccount} matches the singular binding used by the
                // core hosting routes so implicit model binding resolves.
                $router->get('snmp-monitor/accounts/{hostingAccount}', [DashboardController::class, 'show'])
                    ->name('host.show');

                $router->get('snmp-monitor/accounts/{hostingAccount}/series', [DashboardController::class, 'series'])
                    ->name('series');
            });

        // Manual poll trigger behind the hosting-page card's Refresh button.
        // Canonical admin/hosting prefix matching the core hosting lifecycle
        // routes; write-level permission because it enqueues polling work.
        $router->middleware(['web', 'auth', 'admin', 'permission:hosting.manage'])
            ->prefix('admin')
            ->name('admin.snmp-monitor.')
            ->group(function (Router $router): void {
                $router->post('hosting/{hostingAccount}/snmp-monitor/poll', [PollController::class, '__invoke'])
                    ->name('poll');
                $router->post('hosting/{hostingAccount}/snmp-monitor/host', [TargetHostController::class, 'update'])
                    ->name('host.update');

                // Removes the target row AND every sample collected for it.
                // Destructive, so it sits behind hosting.manage alongside the
                // other write actions and is a DELETE, not a GET.
                $router->delete('hosting/{hostingAccount}/snmp-monitor/target', [TargetHostController::class, 'destroy'])
                    ->name('target.destroy');
            });
    }

    public function configSchema(): array
    {
        return ['fields' => [
            [
                'key' => 'snmp_version',
                'label' => 'SNMP Version',
                'type' => 'select',
                'options' => ['v3' => 'SNMPv3', 'v2c' => 'SNMPv2c'],
                'default' => 'v3',
                'section' => 'Connection',
                'help' => 'SNMP protocol version. Use v2c for community-string authentication or v3 for username with authentication and optional privacy.',
            ],
            [
                'key' => 'snmp_community',
                'label' => 'Community String (v2c)',
                'type' => 'text',
                'section' => 'Connection',
                'help' => 'Community string for SNMPv2c. Default is typically public. Only used when SNMP version is v2c.',
                'show_if' => ['snmp_version' => 'v2c'],
            ],
            [
                'key' => 'snmp_port',
                'label' => 'Port',
                'type' => 'number',
                'default' => 161,
                'section' => 'Connection',
                'help' => 'UDP port of the SNMP agent. Default is 161.',
            ],
            [
                'key' => 'snmp_timeout',
                'label' => 'Timeout (seconds)',
                'type' => 'number',
                'default' => 2,
                'section' => 'Connection',
                'help' => 'SNMP request timeout in seconds. Increase for slow or remote agents. Default is 2 seconds.',
            ],
            [
                'key' => 'snmp_username',
                'label' => 'SNMP Username (v3)',
                'type' => 'text',
                'section' => 'SNMPv3 Auth',
                'help' => 'SNMPv3 username for authenticated access. Only used when SNMP version is v3.',
                'show_if' => ['snmp_version' => 'v3'],
            ],
            [
                'key' => 'snmp_auth_password',
                'label' => 'Auth Password (v3)',
                'type' => 'password',
                'encrypted' => true,
                'section' => 'SNMPv3 Auth',
                'help' => 'Authentication passphrase for SNMPv3. Stored encrypted. Only used when SNMP version is v3.',
                'show_if' => ['snmp_version' => 'v3'],
            ],
            [
                'key' => 'snmp_auth_protocol',
                'label' => 'Auth Protocol (v3)',
                'type' => 'select',
                'options' => ['SHA' => 'SHA', 'MD5' => 'MD5'],
                'default' => 'SHA',
                'section' => 'SNMPv3 Auth',
                'help' => 'Hash algorithm for SNMPv3 authentication. SHA is recommended.',
                'show_if' => ['snmp_version' => 'v3'],
            ],
            [
                'key' => 'snmp_priv_password',
                'label' => 'Privacy Password (v3, optional)',
                'type' => 'password',
                'encrypted' => true,
                'section' => 'Privacy',
                'help' => 'Privacy (encryption) passphrase for SNMPv3. Leave empty for authNoPriv. Stored encrypted.',
                'show_if' => ['snmp_version' => 'v3'],
            ],
            [
                'key' => 'snmp_priv_protocol',
                'label' => 'Privacy Protocol (v3)',
                'type' => 'select',
                'options' => ['AES' => 'AES', 'DES' => 'DES'],
                'default' => 'AES',
                'section' => 'Privacy',
                'help' => 'Encryption protocol for SNMPv3 privacy. Only used when a privacy password is set.',
                'show_if' => ['snmp_version' => 'v3'],
            ],
            [
                'key' => 'poll_interval',
                'label' => 'Poll Interval',
                'type' => 'select',
                'options' => [
                    '60' => 'Every 1 minute',
                    '120' => 'Every 2 minutes',
                    '300' => 'Every 5 minutes',
                    '600' => 'Every 10 minutes',
                    '900' => 'Every 15 minutes',
                    '1800' => 'Every 30 minutes',
                    '3600' => 'Every 1 hour',
                ],
                'default' => 300,
                'section' => 'Polling',
                'help' => 'How often SNMP data is collected for hosts on this product. Individual hosts can override this on their hosting page.',
            ],
            [
                'key' => 'collect_cpu',
                'label' => 'Collect CPU Load',
                'type' => 'checkbox',
                'default' => true,
                'section' => 'Metrics',
                'help' => 'Collect CPU load via hrProcessorLoad. Low SNMP walk cost — one walk of the processor table.',
            ],
            [
                'key' => 'collect_memory',
                'label' => 'Collect Memory Usage',
                'type' => 'checkbox',
                'default' => true,
                'section' => 'Metrics',
                'help' => 'Collect physical memory usage via hrMemorySize and hrStorageTable. Low walk cost.',
            ],
            [
                'key' => 'collect_disks',
                'label' => 'Collect Disk Usage',
                'type' => 'checkbox',
                'default' => true,
                'section' => 'Metrics',
                'help' => 'Collect fixed-disk usage from hrStorageTable. Low walk cost — filtered to fixed disks only.',
            ],
            [
                'key' => 'collect_network',
                'label' => 'Collect Network Interfaces',
                'type' => 'checkbox',
                'default' => false,
                'section' => 'Metrics',
                'help' => 'Collect network interfaces via IF-MIB (ifDescr, ifOperStatus, ifInOctets/ifOutOctets). Increases SNMP walk cost and payload size.',
            ],
            [
                'key' => 'collect_processes',
                'label' => 'Collect Running Processes',
                'type' => 'checkbox',
                'default' => false,
                'section' => 'Metrics',
                'help' => 'Collect running processes via hrSWRunTable (top N by CPU/memory). High SNMP walk cost — may be slow on busy hosts.',
            ],
        ]];
    }

    public function sidebarItems(): array
    {
        return [
            [
                'text' => 'SNMP Monitor',
                'route' => 'admin.snmp-monitor.dashboard',
                'icon' => 'bi bi-activity',
                'can' => 'hosting.view',
            ],
        ];
    }

    public function hostingAccountInfo(HostingAccount $account, array $config): ?array
    {
        // The caller (HostingController) only invokes providers whose link is
        // enabled, but the guard stays defensive: no enabled snmp-monitor
        // link on the product means no card, ever.
        $module = app(ModuleManager::class)->find('snmp-monitor');

        if ($module === null || $account->product === null) {
            return null;
        }

        if (! $account->product->moduleLinks->firstWhere('module_id', $module->id)?->enabled) {
            return null;
        }

        try {
            $target = app(TargetService::class)
                ->ensureForAccount($account, TargetService::osFor($account, $config));
        } catch (\Throwable) {
            return null;
        }

        // Per-hosting-account IP selection: expose the account's currently
        // leased IPs so the panel can offer a dropdown. The selected host
        // is the target's stored host (explicit per-account choice).
        $account->loadMissing('ipAddresses.subnet');
        $assignedIps = $account->ipAddresses;

        // The client portal must not see admin routes, poll-target editing
        // or refresh controls. The view checks $isClient to hide them.
        $isClient = request()->routeIs('client.*');

        return [
            'title' => 'SNMP Monitor',
            'icon' => 'bi bi-activity',
            'view' => 'snmp-monitor::panel',
            'data' => [
                'account' => $account,
                'target' => $target,
                // snmp_latest snapshot (decoded) or null before the first poll.
                'latest' => app(SnmpMetricRepository::class)->latest($target),
                'assignedIps' => $assignedIps,
                'isClient' => $isClient,
            ],
        ];
    }
}
