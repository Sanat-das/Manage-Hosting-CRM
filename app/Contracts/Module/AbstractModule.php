<?php

declare(strict_types=1);

namespace App\Contracts\Module;

use Illuminate\Routing\Router;

/**
 * Convenience base for modules that only override the methods they need.
 *
 * Every method has a no-op default, so a module can implement a single
 * capability (e.g. sidebarItems) without boilerplate. Modules that need
 * provision/suspend/unsuspend/terminate behaviour should additionally
 * implement Capabilities\ProvisioningModule — this class does not provide
 * it, to keep optional capabilities compositional.
 */
abstract class AbstractModule implements ModuleContract
{
    public function boot(ModuleContext $context): void
    {
    }

    public function registerRoutes(Router $router): void
    {
    }

    public function sidebarItems(): array
    {
        return [];
    }

    public function configSchema(): array
    {
        return ['fields' => []];
    }

    public function install(): void
    {
    }

    public function activate(): void
    {
    }

    public function deactivate(): void
    {
    }

    public function uninstall(): void
    {
    }
}
