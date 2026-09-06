<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\CrashModule;

use App\Contracts\Module\AbstractModule;
use App\Contracts\Module\ModuleContext;
use Illuminate\Routing\Router;

/**
 * Mirrors tests/Fixtures/Modules/CrashModule/CrashModule.php inside the
 * module folder so the fixture is a self-contained WP-style module package.
 */
class CrashModule extends AbstractModule
{
    public function boot(ModuleContext $context): void
    {
        throw new \RuntimeException('boom');
    }

    public function registerRoutes(Router $router): void
    {
        throw new \RuntimeException('route boom');
    }

    public function sidebarItems(): array
    {
        throw new \RuntimeException('sidebar boom');
    }

    public function configSchema(): array
    {
        return ['fields' => []];
    }
}
