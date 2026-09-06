<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Module\Capabilities\HostingAccountInfoProvider;
use App\Models\Module;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SnmpMonitor module end-to-end: lifecycle, activation and sidebar.
 *
 * Exercises the real snmp-monitor module discovered from base_path('modules').
 */
class SnmpMonitorModuleTest extends TestCase
{
    use RefreshDatabase;

    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(ModuleManager::class);
    }

    // ------------------------------------------------------------------
    // Lifecycle: reconcile discovers the module, activate marks it active.
    // ------------------------------------------------------------------

    public function test_module_reconciles_and_activates(): void
    {
        $module = $this->activateSnmpMonitorModule();

        $this->assertSame(Module::STATUS_ACTIVE, $module->fresh()->status);
        $this->assertContains('snmp-monitor', $this->manager->active()->pluck('slug')->all(), 'Active manager must contain the snmp-monitor slug.');
    }

    // ------------------------------------------------------------------
    // Sidebar: active module contributes sidebar items.
    // ------------------------------------------------------------------

    public function test_sidebar_items_include_snmp_monitor_entry(): void
    {
        $this->activateSnmpMonitorModule();

        $items = $this->manager->sidebarItems();

        $texts = array_column($items, 'text');
        $this->assertContains('SNMP Monitor', $texts);

        $snmpItem = collect($items)->firstWhere('text', 'SNMP Monitor');
        $this->assertSame('admin.snmp-monitor.dashboard', $snmpItem['route']);
        $this->assertSame('bi bi-activity', $snmpItem['icon']);
        $this->assertSame('hosting.view', $snmpItem['can']);
    }

    // ------------------------------------------------------------------
    // Config schema: 14 fields from rdp-console copied verbatim, plus
    // the product-level poll_interval default.
    // ------------------------------------------------------------------

    public function test_config_schema_has_fifteen_fields(): void
    {
        $module = $this->activateSnmpMonitorModule();

        $instance = $this->manager->resolve($module);
        $this->assertNotNull($instance);

        $fields = $instance->configSchema()['fields'];
        $keys = array_column($fields, 'key');

        foreach ([
            'snmp_version',
            'snmp_community',
            'snmp_port',
            'snmp_timeout',
            'snmp_username',
            'snmp_auth_password',
            'snmp_auth_protocol',
            'snmp_priv_password',
            'snmp_priv_protocol',
            'poll_interval',
            'collect_cpu',
            'collect_memory',
            'collect_disks',
            'collect_network',
            'collect_processes',
        ] as $requiredKey) {
            $this->assertContains($requiredKey, $keys, "Schema must contain {$requiredKey}");
        }

        $this->assertCount(15, $fields, 'Schema must have exactly 15 fields.');
    }

    // ------------------------------------------------------------------
    // HostingAccountInfoProvider: null placeholder until card is built.
    // ------------------------------------------------------------------

    public function test_hosting_account_info_returns_null_placeholder(): void
    {
        $module = $this->activateSnmpMonitorModule();

        $instance = $this->manager->resolve($module);
        $this->assertNotNull($instance);

        $this->assertInstanceOf(
            HostingAccountInfoProvider::class,
            $instance,
        );
    }

    // ------------------------------------------------------------------
    // Discovery: invalid slug is NOT discovered.
    // ------------------------------------------------------------------

    public function test_discovery_does_not_contain_bogus_slug(): void
    {
        $this->manager->reconcile();

        $slugs = array_keys($this->manager->discovered());
        $this->assertNotContains('bogus-slug', $slugs);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Reconcile the real modules folder, activate snmp-monitor and replay
     * the side effects ModuleServiceProvider performs for active modules
     * during app boot: provider boot() (view namespace) and route
     * registration.
     */
    private function activateSnmpMonitorModule(): Module
    {
        $this->manager->reconcile();

        $module = $this->manager->find('snmp-monitor');
        $this->assertNotNull($module, 'snmp-monitor module must be discovered from base_path(\'modules\').');

        $this->manager->activate($module);
        $this->assertSame(Module::STATUS_ACTIVE, $module->fresh()->status);

        $instance = $this->manager->resolve($module);
        $this->assertNotNull($instance, 'snmp-monitor provider must resolve.');

        $instance->boot($this->manager->contextFor($module));
        $this->manager->registerModuleRoutes();
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();

        return $module;
    }
}
