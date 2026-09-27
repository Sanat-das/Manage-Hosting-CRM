<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Module;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;

/**
 * Single resolver for a provisioning driver by module slug.
 *
 * Builtin integrations come first (IntegrationRegistry), with the plugin
 * ModuleManager as the fallback — the same order every caller used to
 * hand-roll. Returns null, never throws, so callers can degrade to a clear
 * "module not available" message instead of a 500.
 */
final class ComputeDriver
{
    public static function resolve(string $slug): ?object
    {
        $slug = strtolower(trim($slug));

        if ($slug === '') {
            return null;
        }

        try {
            $registry = app(IntegrationRegistry::class);

            if ($registry->has($slug)) {
                $driver = $registry->instanceFor($slug);

                if ($driver !== null) {
                    return $driver;
                }
            }

            $manager = app(ModuleManager::class);
            $module = $manager->find($slug);

            if ($module !== null && $module->status === Module::STATUS_ACTIVE) {
                return $manager->capabilityInstance($module, 'provisioning');
            }
        } catch (\Throwable) {
            // No driver is a normal, reportable state — not an exception path.
        }

        return null;
    }
}
