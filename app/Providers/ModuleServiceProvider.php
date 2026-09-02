<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Modules\ModuleManager;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleManifestException;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Registers the module manager as a singleton and wires module bootstrap into
 * the application lifecycle: reconcile + boot + sidebar merge during boot(),
 * and module route registration after every provider has booted.
 *
 * Everything is wrapped so a broken module folder can never break provider
 * registration or application bootstrap.
 */
class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class);

        $this->registerModuleAutoloader();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            $manager = $this->app->make(ModuleManager::class);

            $manager->reconcile();
            $manager->boot();

            $menu = config('adminlte.menu', []);
            config(['adminlte.menu' => array_merge($menu, $manager->sidebarItems())]);

            // Module routes load after every core provider has booted and
            // before request dispatch.
            $this->app->booted(function () use ($manager): void {
                $manager->registerModuleRoutes();
            });
        } catch (Throwable $e) {
            error_log('[modules] bootstrap failed: '.$e->getMessage());
        }
    }

    /**
     * Register a PSR-4 runtime autoloader mapping each module's declared
     * namespace to its src directory. Built from a pure folder scan (the DB
     * may not be available yet during register()).
     */
    private function registerModuleAutoloader(): void
    {
        try {
            $map = [];

            foreach (glob(config('modules.path').'/*') ?: [] as $dir) {
                if (! is_dir($dir)) {
                    continue;
                }

                try {
                    $manifest = ModuleManifest::fromDirectory($dir);
                    $map[rtrim($manifest->namespace(), '\\').'\\'] = $dir.'/src';
                } catch (ModuleManifestException) {
                    // Broken module folders must never break registration.
                }
            }

            if ($map === []) {
                return;
            }

            spl_autoload_register(static function (string $class) use ($map): void {
                foreach ($map as $prefix => $src) {
                    if (! str_starts_with($class, $prefix)) {
                        continue;
                    }

                    $file = $src.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                    if (is_file($file)) {
                        require $file;

                        return;
                    }
                }
            });
        } catch (Throwable $e) {
            error_log('[modules] autoloader registration failed: '.$e->getMessage());
        }
    }
}