<?php

declare(strict_types=1);

namespace App\Contracts\Module;

use Illuminate\Routing\Router;

/**
 * Contract every third-party module (WP-style plugin) must implement.
 *
 * The module manager resolves each module's provider class, type-checks it
 * against this contract, and invokes these methods inside try/catch
 * boundaries, so a failing module can never take down the host app.
 *
 * Keep this contract minimal and stable — it is the public API third-party
 * modules compile against. Subclasses that need no-op behaviour for most
 * methods should extend AbstractModule instead of implementing this directly.
 */
interface ModuleContract
{
    /** Called once per request for every active module. Wrapped in try/catch by the manager. */
    public function boot(ModuleContext $context): void;

    /** Register the module's own routes. The manager wraps per-module. */
    public function registerRoutes(Router $router): void;

    /**
     * AdminLTE-style menu item arrays, merged into config/adminlte.php menu.
     *
     * @return array<int, array{text: string, route: ?string, icon: ?string, can: ?string}>
     */
    public function sidebarItems(): array;

    /**
     * Field definitions for the dynamic config form.
     *
     * Allowed types: text, password, textarea, select (with
     * 'options' => ['val' => 'Label']), checkbox, number.
     *
     * @return array{fields: array<int, array{key: string, label: string, type: string, required?: bool, encrypted?: bool, options?: array<string, string>, default?: mixed}>}
     */
    public function configSchema(): array;

    /** Lifecycle hook: called when the module is first installed. */
    public function install(): void;

    /** Lifecycle hook: called when the module is activated. */
    public function activate(): void;

    /** Lifecycle hook: called when the module is deactivated. */
    public function deactivate(): void;

    /** Lifecycle hook: called when the module is uninstalled. */
    public function uninstall(): void;
}
