<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Modules\ModuleManager;
use App\Support\Logging\AppLog;
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
            config(['adminlte.menu' => $this->mergeSidebarItems($menu, $manager->sidebarItems())]);

            // Module routes load after every core provider has booted and
            // before request dispatch.
            $this->app->booted(function () use ($manager): void {
                $manager->registerModuleRoutes();
            });
        } catch (Throwable $e) {
            // IIS FastCGI merges error_log() into the response body (see
            // docs/iis-deployment.md); prefer the file log, keep error_log as
            // the deepest fallback so a logging failure cannot break boot.
            try {
                AppLog::app()->warning('[modules] bootstrap failed: '.$e->getMessage());
            } catch (Throwable) {
                error_log('[modules] bootstrap failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Merge module-provided items into the adminlte menu. Module links belong
     * to the panel, so they go before the top-level CLIENT PORTAL header
     * rather than after it; without that header they are appended. An empty
     * item list leaves the menu untouched.
     *
     * @param  array<int|string, mixed>  $menu
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int|string, mixed>
     */
    public function mergeSidebarItems(array $menu, array $items): array
    {
        if ($items === []) {
            return $menu;
        }

        $position = 0;
        foreach ($menu as $entry) {
            if (is_array($entry) && ($entry['header'] ?? null) === 'CLIENT PORTAL') {
                return array_merge(
                    array_slice($menu, 0, $position, true),
                    $items,
                    array_slice($menu, $position, null, true)
                );
            }
            $position++;
        }

        return array_merge($menu, $items);
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
            try {
                AppLog::app()->warning('[modules] autoloader registration failed: '.$e->getMessage());
            } catch (Throwable) {
                error_log('[modules] autoloader registration failed: '.$e->getMessage());
            }
        }
    }
}
