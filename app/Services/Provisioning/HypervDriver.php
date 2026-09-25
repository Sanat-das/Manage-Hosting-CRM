<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Module;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;

/**
 * Single resolver for the Hyper-V provisioning driver.
 *
 * Builtin integrations are checked first via IntegrationRegistry (the folded
 * in-app modules), with the plugin ModuleManager as the fallback. Returns null
 * — never throws — when no usable driver exists, so callers can degrade to a
 * clear "module not available" message instead of a 500.
 */
final class HypervDriver
{
    public static function resolve(): ?object
    {
        try {
            $registry = app(IntegrationRegistry::class);

            if ($registry->has('hyperv')) {
                $driver = $registry->instanceFor('hyperv');

                if ($driver !== null) {
                    return $driver;
                }
            }

            $manager = app(ModuleManager::class);
            $module = $manager->find('hyperv');

            if ($module !== null && $module->status === Module::STATUS_ACTIVE) {
                return $manager->capabilityInstance($module, 'provisioning');
            }
        } catch (\Throwable) {
            // No driver is a normal, reportable state — not an exception path.
        }

        return null;
    }
}
