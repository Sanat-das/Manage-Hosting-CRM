<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Module;
use App\Models\ModuleLog;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithModuleFixtures;
use Tests\TestCase;

/**
 * Core ModuleManager behaviour: reconcile (folder -> DB rows), provider
 * resolution, config encryption, and — the isolation requirement — proof
 * that a crashing module can never take down the host app.
 */
class ModuleManagerTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithModuleFixtures;

    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpModuleFixtures();
        $this->manager = app(ModuleManager::class);
    }

    public function test_reconcile_creates_installed_rows_from_folder(): void
    {
        $this->manager->reconcile();

        $this->assertSame(2, Module::count());

        $ok = Module::where('slug', 'ok-module')->firstOrFail();
        $this->assertSame(Module::STATUS_INSTALLED, $ok->status);
        $this->assertSame('OK Module', $ok->name);
        $this->assertSame('1.0.0', $ok->version);
        $this->assertSame('Tests\\Fixtures\\Modules\\OkModule\\OkModule', $ok->provider);
        $this->assertSame('ok-module', $ok->manifest['slug']);
        $this->assertSame(['provisioning'], $ok->manifest['capabilities']);
        $this->assertSame(['app' => '>=1.0.0', 'php' => '>=8.0'], $ok->manifest['requires']);

        $crash = Module::where('slug', 'crash-module')->firstOrFail();
        $this->assertSame(Module::STATUS_INSTALLED, $crash->status);
        $this->assertSame('Tests\\Fixtures\\Modules\\CrashModule\\CrashModule', $crash->provider);
        $this->assertSame([], $crash->manifest['capabilities']);
    }

    public function test_reconcile_detects_version_drift(): void
    {
        Module::create([
            'slug' => 'ok-module',
            'name' => 'OK Module',
            'version' => '0.0.1',
            'status' => Module::STATUS_DISABLED,
            'provider' => 'Tests\\Fixtures\\Modules\\OkModule\\OkModule',
            'manifest' => [],
        ]);

        $this->manager->reconcile();

        $module = Module::where('slug', 'ok-module')->firstOrFail();
        $this->assertSame('1.0.0', $module->version);
        $this->assertSame(Module::STATUS_DISABLED, $module->status, 'Reconcile must never touch status.');
        $this->assertSame('ok-module', $module->manifest['slug'], 'Manifest refreshed on version drift.');
    }

    public function test_resolve_returns_null_for_broken_provider(): void
    {
        $module = Module::create([
            'slug' => 'broken-provider',
            'name' => 'Broken Provider',
            'version' => '1.0.0',
            'status' => Module::STATUS_INSTALLED,
            'provider' => 'Nonexistent\\Class',
            'manifest' => [],
        ]);

        $this->assertNull($this->manager->resolve($module), 'A broken provider resolves to null, never throws.');

        $this->assertDatabaseHas('module_log', [
            'module_id' => $module->id,
            'event' => 'resolve',
            'status' => 'failed',
        ]);
    }

    public function test_encrypt_decrypt_config_roundtrip(): void
    {
        $module = Module::create([
            'slug' => 'ok-module',
            'name' => 'OK Module',
            'version' => '1.0.0',
            'status' => Module::STATUS_INSTALLED,
            'provider' => 'Tests\\Fixtures\\Modules\\OkModule\\OkModule',
            'manifest' => [],
        ]);

        $encrypted = $this->manager->encryptConfig($module, [
            'greeting' => 'hi',
            'secret' => 's3cret',
        ]);

        $this->assertSame('hi', $encrypted['greeting'], 'Non-encrypted fields pass through untouched.');
        $this->assertStringStartsWith('eyJ', $encrypted['secret']);

        // Already-encrypted values are skipped, not double-encrypted.
        $reEncrypted = $this->manager->encryptConfig($module, [
            'greeting' => 'hi',
            'secret' => $encrypted['secret'],
        ]);
        $this->assertSame($encrypted['secret'], $reEncrypted['secret']);

        $this->assertSame([
            'greeting' => 'hi',
            'secret' => 's3cret',
        ], $this->manager->decryptConfig($module, $encrypted));
    }

    public function test_boot_with_crashing_module_marks_it_crashed_and_does_not_throw(): void
    {
        $this->manager->reconcile();

        $ok = $this->manager->find('ok-module');
        $this->manager->activate($ok);

        $crash = $this->manager->find('crash-module');
        $this->manager->activate($crash);

        $this->manager->boot(); // Must never throw, even with a crashing module.

        $this->assertSame(Module::STATUS_ACTIVE, $ok->fresh()->status, 'Healthy module survives a sibling crash.');

        $crashed = $crash->fresh();
        $this->assertSame(Module::STATUS_CRASHED, $crashed->status);
        $this->assertNotNull($crashed->crashed_at);
    }

    public function test_sidebar_items_skips_crashing_module(): void
    {
        $this->manager->reconcile();
        $this->manager->activate($this->manager->find('ok-module'));
        $this->manager->activate($this->manager->find('crash-module'));

        $items = $this->manager->sidebarItems(); // Must never throw.

        $this->assertSame([], $items);
        $this->assertSame(Module::STATUS_CRASHED, $this->manager->find('crash-module')->status);
        $this->assertSame(Module::STATUS_ACTIVE, $this->manager->find('ok-module')->status);
    }
}
