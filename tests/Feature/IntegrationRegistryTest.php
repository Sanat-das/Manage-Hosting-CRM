<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Integrations\Capabilities\ProvisioningModule;
use App\Contracts\Integrations\TestableServerModule;
use App\Models\Server;
use App\Modules\HyperV\HyperV;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the built-in integration registry: the six provisioning/serve
 * integrations are always available to the application, resolvable by slug,
 * and carry the metadata the server-type grid and product forms render.
 *
 * These integrations used to be plugin modules discovered from modules/ and
 * tracked in the `modules` table; they are now in-app code registered in
 * config/integrations.php. A missing or renamed entry here means a server
 * type or provisioning driver silently disappears from the UI.
 */
final class IntegrationRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** Slug => the server-type group the grid renders it under. */
    private const BUILTINS = [
        'cpanel' => 'panel',
        'plesk' => 'panel',
        'directadmin' => 'panel',
        'virtualizor' => 'virtualization',
        'hyperv' => 'virtualization',
        'proxmox' => 'virtualization',
    ];

    private IntegrationRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(IntegrationRegistry::class);
    }

    public function test_every_builtin_is_registered_with_metadata(): void
    {
        foreach (self::BUILTINS as $slug => $group) {
            $this->assertTrue($this->registry->has($slug), "Builtin [$slug] is not registered.");

            $entry = $this->registry->entry($slug);
            $this->assertIsArray($entry, "Builtin [$slug] has no metadata entry.");
            $this->assertArrayHasKey('class', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('group', $entry);
            $this->assertArrayHasKey('description', $entry);
            $this->assertTrue(class_exists($entry['class']), "Builtin [$slug] class does not exist.");
            $this->assertNotSame('', trim((string) $entry['name']), "Builtin [$slug] has no display name.");
            $this->assertSame($group, $entry['group'], "Builtin [$slug] is in the wrong grid group.");
        }

        $this->assertEqualsCanonicalizing(array_keys(self::BUILTINS), $this->registry->slugs());
    }

    public function test_every_builtin_resolves_to_a_provisioning_server_driver(): void
    {
        foreach (array_keys(self::BUILTINS) as $slug) {
            $instance = $this->registry->instanceFor($slug);

            $this->assertInstanceOf(ProvisioningModule::class, $instance, "[$slug] must be a provisioning driver.");
            $this->assertInstanceOf(TestableServerModule::class, $instance, "[$slug] must be testable as a server type.");

            $schema = $this->registry->configSchemaFor($slug);
            $this->assertArrayHasKey('fields', $schema, "[$slug] exposes no config schema.");
        }
    }

    public function test_server_type_options_cover_every_builtin(): void
    {
        $options = collect($this->registry->serverTypeOptions())->keyBy('value');

        foreach (self::BUILTINS as $slug => $group) {
            $this->assertTrue($options->has($slug), "Server-type grid is missing [$slug].");

            $option = $options->get($slug);
            $this->assertSame($slug, $option['slug']);
            $this->assertSame($group, $option['group']);
            $this->assertNotSame('', trim((string) $option['label']));
            $this->assertArrayHasKey('description', $option);
        }
    }

    public function test_resolve_for_server_uses_the_server_type_slug(): void
    {
        $server = Server::create([
            'name' => 'hv-registry-test',
            'ip_address' => '10.0.0.9',
            'server_type' => 'hyperv',
            'api_url' => 'http://10.0.0.9:5985',
            'api_username' => 'admin',
            'api_password_encrypted' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
        ]);

        $this->assertInstanceOf(HyperV::class, $this->registry->resolveForServer($server));
    }

    public function test_linkable_modules_mark_builtins_as_builtin(): void
    {
        $modules = collect($this->registry->linkableModules())->keyBy('slug');

        foreach (array_keys(self::BUILTINS) as $slug) {
            $this->assertTrue($modules->has($slug), "Product module list is missing [$slug].");

            $module = $modules->get($slug);
            $this->assertTrue($module['builtin'], "[$slug] must be flagged builtin.");
            $this->assertNotSame('', trim((string) $module['name']));
        }
    }

    public function test_unknown_slugs_degrade_gracefully(): void
    {
        $this->assertFalse($this->registry->has('not-a-real-integration'));
        $this->assertNull($this->registry->entry('not-a-real-integration'));
        $this->assertNull($this->registry->instanceFor('not-a-real-integration'));
        $this->assertSame('not-a-real-integration', $this->registry->nameFor('not-a-real-integration'));
    }

    public function test_plugin_module_config_encryption_delegates_to_the_module_manager(): void
    {
        app(ModuleManager::class)->reconcile();

        $encrypted = $this->registry->encryptConfigFor('snmp-monitor', [
            'snmp_auth_password' => 'super-secret',
        ]);

        $this->assertNotSame('super-secret', $encrypted['snmp_auth_password']);

        $decrypted = $this->registry->decryptConfigFor('snmp-monitor', $encrypted);

        $this->assertSame('super-secret', $decrypted['snmp_auth_password']);
    }
}
