<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Module\Capabilities\HostingAccountInfoProvider;
use App\Models\Module;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Linux VPS module shell tests (SSH-only scope): manifest identity, route
 * surface and product-config schema.
 *
 * SNMP collection, snapshots and panels were moved to the standalone
 * snmp-monitor module — these assertions pin the strip: the refresh route,
 * the snmp/collect product-config fields and the hosting-account info
 * capability must stay gone. SSH config CRUD and terminal lifecycle
 * behaviour are covered by SshConsoleTerminalTest.
 */
class SshConsoleModuleTest extends TestCase
{
    use RefreshDatabase;

    private ?Module $sshConsoleModule = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateSshConsoleModule();
    }

    // ------------------------------------------------------------------
    // a) The module reconciles and carries its SSH-only manifest name.
    // ------------------------------------------------------------------

    public function test_module_activates_with_ssh_only_manifest_name(): void
    {
        $this->assertNotNull($this->sshConsoleModule);
        $this->assertSame('active', $this->sshConsoleModule->status);
        $this->assertSame('SSH Console', $this->sshConsoleModule->name);
    }

    // ------------------------------------------------------------------
    // b) All SSH routes stay registered; the SNMP refresh route is gone.
    // ------------------------------------------------------------------

    public function test_ssh_routes_registered_and_refresh_route_removed(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['edit', 'update', 'open', 'input', 'resize', 'close', 'stream', 'password', 'html'] as $suffix) {
            $this->assertNotNull(
                $routes->getByName("admin.ssh-console.{$suffix}"),
                "Route [admin.ssh-console.{$suffix}] should be registered."
            );
        }

        $this->assertNull(
            $routes->getByName('admin.ssh-console.refresh'),
            'The SNMP refresh route must no longer exist.'
        );
    }

    // ------------------------------------------------------------------
    // c) No snmp_*/collect_* product-config fields remain, and the module
    //    no longer advertises hosting-account info (SNMP panel).
    // ------------------------------------------------------------------

    public function test_config_schema_is_empty_and_info_capability_stripped(): void
    {
        $instance = app(ModuleManager::class)->resolve($this->sshConsoleModule);

        $this->assertNotNull($instance);
        $this->assertSame([], $instance->configSchema()['fields']);
        $this->assertNotContains(
            HostingAccountInfoProvider::class,
            class_implements($instance) ?: [],
        );
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Reconcile + activate ssh-console like RdpConsoleModuleTest does,
     * replaying provider boot/route side effects so route() resolves.
     */
    private function activateSshConsoleModule(): void
    {
        if ($this->sshConsoleModule !== null) {
            return;
        }

        $manager = app(ModuleManager::class);
        $manager->reconcile();

        $module = $manager->find('ssh-console');

        $this->assertNotNull($module, 'ssh-console module should be discoverable.');

        $manager->activate($module);

        $instance = $manager->resolve($module);

        if ($instance !== null) {
            $instance->boot($manager->contextFor($module));
        }

        $manager->registerModuleRoutes();
        // Routes added after the router was compiled have a stale nameList:
        // refresh rebuilds the tables so route() resolves the final name.
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();

        $this->sshConsoleModule = $module;
    }
}
