<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Module;
use App\Services\Modules\ModuleManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithModuleFixtures;
use Tests\TestCase;

/**
 * Module lifecycle: activate runs the module's own migrations and hook,
 * deactivate marks the module disabled, uninstall reverses everything and
 * removes the folder, and a module can be installed from a ZIP archive.
 */
class ModuleLifecycleTest extends TestCase
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

    public function test_activate_runs_migrations_and_hook(): void
    {
        $this->manager->reconcile();
        $module = $this->manager->find('ok-module');

        $this->manager->activate($module);

        $this->assertSame(Module::STATUS_ACTIVE, $module->fresh()->status);
        $this->assertTrue(Schema::hasTable('ok_widgets'), 'Module migration must run on activate.');
        $this->assertSame(1, DB::table('module_migrations')->where('module_id', $module->id)->count());
    }

    public function test_deactivate_marks_disabled(): void
    {
        $this->manager->reconcile();
        $module = $this->manager->find('ok-module');

        $this->manager->activate($module);
        $this->manager->deactivate($module);

        $this->assertSame(Module::STATUS_DISABLED, $module->fresh()->status);
    }

    public function test_uninstall_rolls_back_migrations_deletes_row_and_folder(): void
    {
        $this->manager->reconcile();
        $module = $this->manager->find('ok-module');
        $this->manager->activate($module);
        $this->assertTrue(Schema::hasTable('ok_widgets'));

        $this->manager->uninstall($module);

        $this->assertNull(Module::where('slug', 'ok-module')->first());
        $this->assertFalse(Schema::hasTable('ok_widgets'), 'Uninstall must roll back the module migration.');
        $this->assertSame(0, DB::table('module_migrations')->count());
        $this->assertDirectoryDoesNotExist(base_path('tests/Fixtures/modules/ok-module'));
    }

    public function test_install_from_zip(): void
    {
        // The crash-module folder is restored by setUp; remove it so the
        // install has a clean target (installFromZip rejects existing slugs).
        (new Filesystem())->deleteDirectory(base_path('tests/Fixtures/modules/crash-module'));

        $zipPath = sys_get_temp_dir().'/crash-module-'.uniqid().'.zip';
        $this->makeZip(base_path('tests/Fixtures/modules-pristine/crash-module'), $zipPath);

        try {
            $module = $this->manager->installFromZip($zipPath);

            $this->assertSame('crash-module', $module->slug);
            $this->assertSame(Module::STATUS_INSTALLED, $module->fresh()->status);
            $this->assertSame('Tests\\Fixtures\\Modules\\CrashModule\\CrashModule', $module->fresh()->provider);
            $this->assertDirectoryExists(base_path('tests/Fixtures/modules/crash-module'));
        } finally {
            $installed = Module::where('slug', 'crash-module')->first();

            if ($installed !== null) {
                $this->manager->uninstall($installed);
            }

            unlink($zipPath);
        }
    }

    /**
     * Build a ZIP archive of a directory so module.json sits at the archive
     * root (the layout installFromZip expects).
     */
    private function makeZip(string $sourceDir, string $zipPath): void
    {
        $sourceDir = rtrim(str_replace('\\', '/', realpath($sourceDir) ?: $sourceDir), '/');

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($absolute, strlen($sourceDir)), '/');

            $zip->addFile($file->getPathname(), $relative);
        }

        $zip->close();
    }
}
