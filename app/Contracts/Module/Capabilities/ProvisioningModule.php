<?php

declare(strict_types=1);

namespace App\Contracts\Module\Capabilities;

use App\Contracts\Module\ProvisioningResult;
use App\Models\ServiceInstance;

/**
 * Optional capability: modules that actually create and manage the
 * infrastructure behind a service instance.
 *
 * The manager type-checks a module against this interface before ever
 * routing a provisioning call to it; modules that do not implement it are
 * treated as non-provisioning. $config is the ALREADY-DECRYPTED module
 * config (see ModuleContext), passed through by the manager.
 */
interface ProvisioningModule
{
    /** Create the resource represented by the service instance. */
    public function provision(ServiceInstance $service, array $config): ProvisioningResult;

    /** Temporarily disable the resource (e.g. unpaid invoice). */
    public function suspend(ServiceInstance $service, array $config): ProvisioningResult;

    /** Re-enable a suspended resource. */
    public function unsuspend(ServiceInstance $service, array $config): ProvisioningResult;

    /** Permanently destroy the resource and release its identifiers. */
    public function terminate(ServiceInstance $service, array $config): ProvisioningResult;
}
