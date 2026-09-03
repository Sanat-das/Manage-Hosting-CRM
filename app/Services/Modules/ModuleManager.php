<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Contracts\Module\Capabilities\ProvisioningModule;
use App\Contracts\Module\ModuleContract;
use App\Contracts\Module\ModuleContext;
use App\Jobs\RunModuleCapability;
use App\Models\Module;
use App\Models\ModuleLog;
use App\Models\ServiceInstance;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleManifestException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use PDOException;
use Throwable;
use ZipArchive;

/**
 * ModuleManager — the heart of the WP-style module system.
 *
 * Every module-facing call is wrapped so a broken module can never take down
 * the host app: resolution failures are logged, fatals are caught by shutdown
 * handlers, module work runs in queued jobs, and module migrations are scoped
 * and reversible. No public method called during request bootstrap throws.
 */
class ModuleManager
{
    /**
     * Absolute path to the modules directory.
     */
    public function directory(): string
    {
        return config('modules.path');
    }

    /**
     * Scan the modules directory for valid manifests.
     *
     * @return array<string, ModuleManifest>  keyed by manifest slug
     */
    public function discovered(): array
    {
        $manifests = [];

        foreach (glob($this->directory().'/*') ?: [] as $entry) {
            if (! is_dir($entry)) {
                continue;
            }

            try {
                $manifest = ModuleManifest::fromDirectory($entry);
                $manifests[$manifest->slug()] = $manifest;
            } catch (ModuleManifestException $e) {
                $this->log(null, 'discover', 'failed', $e->getMessage());
            }
        }

        return $manifests;
    }

    /**
     * Sync the modules table with the manifests on disk: create missing rows
     * (status installed) and refresh version + manifest on drift. Never
     * touches status. Silently returns when the table does not exist yet
     * (first run before migrations).
     */
    public function reconcile(): void
    {
        try {
            foreach ($this->discovered() as $slug => $manifest) {
                $module = Module::query()->where('slug', $slug)->first();

                if ($module === null) {
                    Module::query()->create([
                        'slug' => $slug,
                        'name' => $manifest->name(),
                        'version' => $manifest->version(),
                        'status' => Module::STATUS_INSTALLED,
                        'provider' => $manifest->provider(),
                        'manifest' => $manifest->toArray(),
                    ]);

                    continue;
                }

                if ($module->version !== $manifest->version()) {
                    $module->version = $manifest->version();
                    $module->manifest = $manifest->toArray();
                    $module->save();
                }
            }
        } catch (QueryException | PDOException) {
            // Modules table not migrated yet — nothing to reconcile.
        }
    }

    /**
     * Every registered module, ordered by name.
     */
    public function all(): Collection
    {
        return Module::query()->orderBy('name')->get();
    }

    /**
     * Modules currently active, ordered by name.
     */
    public function active(): Collection
    {
        return Module::query()->where('status', Module::STATUS_ACTIVE)->orderBy('name')->get();
    }

    public function find(string $slug): ?Module
    {
        return Module::query()->where('slug', $slug)->first();
    }

    /**
     * Resolve the module's provider class through the container and verify it
     * implements ModuleContract. Never throws — failures are logged and null
     * is returned.
     */
    public function resolve(Module $module): ?ModuleContract
    {
        try {
            $instance = app($module->provider);

            if (! $instance instanceof ModuleContract) {
                throw new \RuntimeException(sprintf(
                    'Module provider [%s] must implement %s.',
                    $module->provider,
                    ModuleContract::class,
                ));
            }

            return $instance;
        } catch (Throwable $e) {
            $this->log($module, 'resolve', 'failed', $e->getMessage());

            return null;
        }
    }

    /**
     * Immutable request context for a module: the backing row plus its
     * already-decrypted config.
     */
    public function contextFor(Module $module): ModuleContext
    {
        return new ModuleContext($module, $this->decryptConfig($module, $module->config ?? []));
    }

    /**
     * Boot every active module. A shutdown handler per module catches fatal
     * errors (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR) and crashes the
     * module when one occurs while it is still active; boot() itself is
     * wrapped so a throwing module is crashed instead of killing the request.
     */
    public function boot(): void
    {
        try {
            foreach ($this->active() as $module) {
                register_shutdown_function(function () use ($module): void {
                    $error = error_get_last();

                    if ($error === null || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                        return;
                    }

                    $fresh = Module::query()->find($module->id);

                    if ($fresh !== null && $fresh->status === Module::STATUS_ACTIVE) {
                        $this->crash($module, ['message' => $error['message'] ?? 'Unknown fatal error']);
                    }
                });

                try {
                    $instance = $this->resolve($module);

                    if ($instance !== null) {
                        $instance->boot($this->contextFor($module));
                    }
                } catch (Throwable $e) {
                    $this->crash($module, ['message' => $e->getMessage()]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('[modules] boot failed: '.$e->getMessage());
        }
    }

    /**
     * Mark a module as crashed, log the event, and flash a warning when a
     * session is available. Never throws.
     *
     * @param  array{message?: string}  $error
     */
    public function crash(Module $module, array $error): void
    {
        try {
            $module->status = Module::STATUS_CRASHED;
            $module->crashed_at = now();
            $module->save();

            $this->log($module, 'crash', 'failed', $error['message'] ?? 'Unknown fatal error');

            if (Session::isStarted()) {
                Session::flash('warning', "Module {$module->name} crashed and was disabled: ".($error['message'] ?? 'unknown error'));
            }
        } catch (Throwable) {
            // Crash handling must never throw.
        }
    }

    /**
     * Sidebar items contributed by active modules, flattened into a single
     * list. Returns [] on any infrastructure failure.
     *
     * @return array<int, array{text: string, route: ?string, icon: ?string, can: ?string}>
     */
    public function sidebarItems(): array
    {
        try {
            $items = [];

            foreach ($this->active() as $module) {
                try {
                    $instance = $this->resolve($module);

                    if ($instance === null) {
                        continue;
                    }

                    $items[] = $instance->sidebarItems();
                } catch (Throwable $e) {
                    $this->crash($module, ['message' => $e->getMessage()]);
                }
            }

            return array_merge([], ...$items);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Let every active module register its own routes. A failing module is
     * crashed and skipped — route registration can never be broken by one.
     */
    public function registerModuleRoutes(): void
    {
        try {
            foreach ($this->active() as $module) {
                try {
                    $instance = $this->resolve($module);

                    if ($instance === null) {
                        continue;
                    }

                    $instance->registerRoutes(Route::getFacadeRoot());
                } catch (Throwable $e) {
                    $this->crash($module, ['message' => $e->getMessage()]);
                }
            }

            // Routes registered after the initial RouteCollection compilation
            // (e.g. mid-test after RefreshDatabase) have a stale nameList:
            // RouteCollection::add() captures the name at add() time (prefix
            // only) and ->name('…') mutates the Route afterwards without
            // updating the lookup. Rebuilding the tables makes every
            // fluently-named route resolvable via route() / getByName().
            try {
                $routes = Route::getFacadeRoot()->getRoutes();
                $routes->refreshNameLookups();
                $routes->refreshActionLookups();
            } catch (Throwable) {
                // Lookup refresh must never break route registration.
            }
        } catch (Throwable $e) {
            Log::warning('[modules] route registration failed: '.$e->getMessage());
        }
    }

    /**
     * Install a module from an uploaded ZIP archive.
     *
     * Validates the archive, extracts it to a temp dir, locates module.json
     * (root or single wrapping folder), validates the manifest, sanity-checks
     * app compatibility and provider loadability, copies the module into the
     * modules directory, registers the row, and runs its migrations.
     *
     * @throws ModuleManifestException|Throwable on any failure (partial
     *                                            target is cleaned up).
     */
    public function installFromZip(string $path): Module
    {
        $tempBase = storage_path('framework/temp-modules');
        $tempDir = $tempBase.'/'.uniqid('module-', true);
        $target = null;

        try {
            if (! is_file($path)) {
                throw new \InvalidArgumentException("Uploaded file not found at [{$path}].");
            }

            $mime = mime_content_type($path);

            if (! str_ends_with(strtolower($path), '.zip') && ! in_array($mime, ['application/zip', 'application/x-zip-compressed'], true)) {
                throw new \InvalidArgumentException('Uploaded file is not a ZIP archive.');
            }

            if (! class_exists(ZipArchive::class)) {
                throw new \RuntimeException('The ZipArchive extension is not available.');
            }

            if (! is_dir($tempBase)) {
                mkdir($tempBase, 0755, true);
            }

            mkdir($tempDir, 0755, true);

            $zip = new ZipArchive();

            if ($zip->open($path) !== true) {
                throw new \RuntimeException('Unable to open the ZIP archive.');
            }

            $zip->extractTo($tempDir);
            $zip->close();

            $root = $this->locateModuleRoot($tempDir);

            $manifest = ModuleManifest::fromDirectory($root);

            if (! $manifest->compatibleWithApp()) {
                throw new ModuleManifestException('Module is not compatible with this application version.');
            }

            $this->registerTemporaryAutoloader($manifest, $root);

            if (! class_exists($manifest->provider())) {
                throw new ModuleManifestException("Module provider class [{$manifest->provider()}] could not be loaded.");
            }

            $realRoot = realpath($root);
            $realTemp = realpath($tempDir);

            if ($realRoot === false || $realTemp === false || ! str_starts_with($realRoot, $realTemp)) {
                throw new ModuleManifestException('Module archive path is invalid.');
            }

            // Slug is already constrained to ^[a-z0-9-]+$ by the manifest, so
            // the target can never escape the modules directory.
            $target = $this->directory().'/'.$manifest->slug();

            if (is_dir($target)) {
                throw new \RuntimeException("A module with slug [{$manifest->slug()}] is already installed.");
            }

            $fs = new Filesystem();
            $fs->makeDirectory(dirname($target), 0755, true, true);
            $fs->copyDirectory($root, $target);

            $module = Module::query()->create([
                'slug' => $manifest->slug(),
                'name' => $manifest->name(),
                'version' => $manifest->version(),
                'status' => Module::STATUS_INSTALLED,
                'provider' => $manifest->provider(),
                'manifest' => $manifest->toArray(),
            ]);

            try {
                app(ModuleMigrationRunner::class)->migrate($module);
            } catch (Throwable $e) {
                $module->delete();

                if (is_dir($target)) {
                    $fs->deleteDirectory($target);
                }

                throw $e;
            }

            return $module;
        } catch (Throwable $e) {
            if ($target !== null && is_dir($target)) {
                try {
                    (new Filesystem())->deleteDirectory($target);
                } catch (Throwable) {
                    // Best-effort cleanup of the partial target.
                }
            }

            throw $e;
        } finally {
            if (is_dir($tempDir)) {
                try {
                    (new Filesystem())->deleteDirectory($tempDir);
                } catch (Throwable) {
                    // Best-effort cleanup of the temp dir.
                }
            }
        }
    }

    /**
     * Activate a module: run any unrun migrations, then fire its activate()
     * hook. Failures are logged and rethrown.
     */
    public function activate(Module $module): void
    {
        $module->status = Module::STATUS_ACTIVE;
        $module->save();

        app(ModuleMigrationRunner::class)->migrate($module);

        try {
            $instance = $this->resolve($module);

            if ($instance !== null) {
                $instance->activate();
            }
        } catch (Throwable $e) {
            $this->log($module, 'activate', 'failed', $e->getMessage());

            throw $e;
        }
    }

    /**
     * Deactivate a module: mark it disabled and fire its deactivate() hook.
     * Failures are logged and rethrown.
     */
    public function deactivate(Module $module): void
    {
        $module->status = Module::STATUS_DISABLED;
        $module->save();

        try {
            $instance = $this->resolve($module);

            if ($instance !== null) {
                $instance->deactivate();
            }
        } catch (Throwable $e) {
            $this->log($module, 'deactivate', 'failed', $e->getMessage());

            throw $e;
        }
    }

    /**
     * Uninstall a module: fire its uninstall() hook (best effort), roll back
     * its migrations (best effort), delete the DB row, then delete the module
     * folder. The row is deleted before the folder so the migration runner
     * still has the record while rolling back.
     */
    public function uninstall(Module $module): void
    {
        try {
            $instance = $this->resolve($module);

            if ($instance !== null) {
                $instance->uninstall();
            }
        } catch (Throwable $e) {
            $this->log($module, 'uninstall', 'failed', $e->getMessage());
        }

        try {
            app(ModuleMigrationRunner::class)->rollback($module);
        } catch (Throwable) {
            // Best-effort rollback.
        }

        $module->delete();

        $dir = $this->directory().'/'.$module->slug;

        if (is_dir($dir)) {
            try {
                (new Filesystem())->deleteDirectory($dir);
            } catch (Throwable) {
                // Best-effort folder removal.
            }
        }
    }

    /**
     * Encrypt the values of schema fields marked 'encrypted'. Values that are
     * null, empty, or already encrypted (Laravel ciphertext starts with the
     * 'eyJ' base64 marker) pass through untouched; fields not in the schema
     * pass through unchanged.
     */
    public function encryptConfig(Module $module, array $config): array
    {
        foreach ($this->configFields($module) as $field) {
            $key = $field['key'] ?? null;

            if (! is_string($key) || ! ($field['encrypted'] ?? false)) {
                continue;
            }

            $value = $config[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (is_string($value) && str_starts_with($value, 'eyJ')) {
                continue;
            }

            $config[$key] = Crypt::encryptString((string) $value);
        }

        return $config;
    }

    /**
     * Decrypt the values of schema fields marked 'encrypted'. Values that
     * fail to decrypt keep their raw value.
     */
    public function decryptConfig(Module $module, array $config): array
    {
        foreach ($this->configFields($module) as $field) {
            $key = $field['key'] ?? null;

            if (! is_string($key) || ! ($field['encrypted'] ?? false)) {
                continue;
            }

            $value = $config[$key] ?? null;

            if ($value === null || $value === '' || ! is_string($value) || ! str_starts_with($value, 'eyJ')) {
                continue;
            }

            try {
                $config[$key] = Crypt::decryptString($value);
            } catch (Throwable) {
                // Keep the raw value when decryption fails.
            }
        }

        return $config;
    }

    /**
     * The capability names the module's provider supports.
     *
     * @return list<string>
     */
    public function capabilities(Module $module): array
    {
        $instance = $this->resolve($module);

        if ($instance === null) {
            return [];
        }

        $capabilities = [];

        if ($instance instanceof ProvisioningModule) {
            $capabilities[] = 'provisioning';
        }

        return $capabilities;
    }

    /**
     * The module's provider when it implements the capability interface for
     * the given name, null otherwise. Never throws.
     */
    public function capabilityInstance(Module $module, string $capability): ?object
    {
        $interface = $this->capabilityInterfaces()[$capability] ?? null;

        if ($interface === null) {
            return null;
        }

        try {
            $instance = $this->resolve($module);

            return $instance instanceof $interface ? $instance : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Queue a capability call so module work never runs inline in request
     * paths.
     */
    public function dispatchCapability(Module $module, string $capability, string $method, ServiceInstance $service, array $config): void
    {
        RunModuleCapability::dispatch(
            $module->id,
            $capability,
            $method,
            $service->id,
            $config,
        );
    }

    /**
     * @return array<string, class-string>
     */
    private function capabilityInterfaces(): array
    {
        return [
            'provisioning' => ProvisioningModule::class,
        ];
    }

    /**
     * The 'fields' list of the module's config schema, or [] when the module
     * cannot be resolved or exposes no schema. Never throws.
     *
     * @return array<int, array<string, mixed>>
     */
    private function configFields(Module $module): array
    {
        try {
            $instance = $this->resolve($module);

            if ($instance === null) {
                return [];
            }

            $schema = $instance->configSchema();

            return is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Locate the module root inside an extracted archive: module.json at the
     * top level, or inside a single wrapping folder.
     */
    private function locateModuleRoot(string $tempDir): string
    {
        if (is_file($tempDir.'/module.json')) {
            return $tempDir;
        }

        foreach (glob($tempDir.'/*') ?: [] as $entry) {
            if (is_dir($entry) && is_file($entry.'/module.json')) {
                return $entry;
            }
        }

        throw new ModuleManifestException('The ZIP archive does not contain a module.json at its root.');
    }

    /**
     * Register a PSR-4 autoloader for the manifest's declared namespace
     * pointing at the extracted src dir, so the provider class can be loaded
     * for the install sanity check.
     */
    private function registerTemporaryAutoloader(ModuleManifest $manifest, string $root): void
    {
        $prefix = rtrim($manifest->namespace(), '\\').'\\';
        $src = $root.'/src';

        spl_autoload_register(static function (string $class) use ($prefix, $src): void {
            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $file = $src.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }

    private function log(?Module $module, string $event, string $status, ?string $error = null, ?int $serviceInstanceId = null): void
    {
        try {
            ModuleLog::create([
                'module_id' => $module?->id,
                'event' => $event,
                'status' => $status,
                'error' => $error,
                'service_instance_id' => $serviceInstanceId,
            ]);
        } catch (Throwable) {
            // Logging must never break module management.
        }
    }
}