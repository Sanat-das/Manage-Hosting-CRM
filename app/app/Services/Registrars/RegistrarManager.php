<?php

declare(strict_types=1);

namespace App\Services\Registrars;

use App\Contracts\RegistrarDriver;
use App\Models\RegistrarSetting;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * RegistrarManager — registry of domain registrars and their drivers.
 *
 * Resolves a registrar's driver through the container, injecting the
 * registrar name and settings so the driver can answer credential/config
 * questions (isConfigured, mode-based endpoints) without carrying any state
 * of its own.
 */
class RegistrarManager
{
    /**
     * All registered registrar names, ordered alphabetically.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return RegistrarSetting::registrars();
    }

    /**
     * Registrar names currently enabled (enabled setting === '1').
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            RegistrarSetting::registrars(),
            static fn (string $registrar): bool => RegistrarSetting::get($registrar, 'enabled') === '1',
        ));
    }

    /**
     * Look up a single registrar's settings by its unique code/name.
     *
     * @return array<string, string>|null key => value settings, or null when no rows exist
     */
    public function findByCode(string $code): ?array
    {
        $settings = RegistrarSetting::allFor($code);

        return $settings === [] ? null : $settings;
    }

    /**
     * Resolve the driver instance bound to the given registrar.
     *
     * @param  array<string, string>|string  $registrar  Registrar name (string) or its settings array
     *
     * @throws InvalidArgumentException when the registrar references an unknown
     *                                  driver class or one that is not a driver
     */
    public function driverFor(array|string $registrar): RegistrarDriver
    {
        if (is_string($registrar)) {
            $name = $registrar;
            $settings = RegistrarSetting::allFor($registrar);
        } else {
            $settings = $registrar;
            $candidate = $registrar['registrar'] ?? null;
            $name = is_string($candidate) ? $candidate : null;
        }

        $driverClass = $settings['driver'] ?? null;

        if (! is_string($driverClass) || $driverClass === '') {
            $driverClass = sprintf(
                'App\\Services\\Registrars\\%sDriver',
                Str::studly((string) $name),
            );
        }

        if (! class_exists($driverClass)) {
            throw new InvalidArgumentException(sprintf('Unknown registrar driver [%s].', $driverClass));
        }

        $driver = app($driverClass, ['registrar' => $name, 'settings' => $settings]);

        if (! $driver instanceof RegistrarDriver) {
            throw new InvalidArgumentException(sprintf(
                'Registrar driver [%s] must implement %s.',
                $driverClass,
                RegistrarDriver::class,
            ));
        }

        return $driver;
    }

    /**
     * Resolve a registrar's driver by code, or null when the registrar is missing.
     */
    public function driver(string $code): ?RegistrarDriver
    {
        $settings = $this->findByCode($code);

        return $settings === null ? null : $this->driverFor($code);
    }

    /**
     * The names of every registered registrar.
     *
     * @return list<string>
     */
    public function supportedCodes(): array
    {
        return $this->all();
    }
}
