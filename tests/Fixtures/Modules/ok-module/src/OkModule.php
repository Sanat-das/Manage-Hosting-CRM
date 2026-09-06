<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\OkModule;

use App\Contracts\Module\AbstractModule;
use App\Contracts\Module\Capabilities\ProvisioningModule;
use App\Contracts\Module\ProvisioningResult;
use App\Models\ServiceInstance;

/**
 * Mirrors tests/Fixtures/Modules/OkModule/OkModule.php inside the module
 * folder so the fixture is a self-contained WP-style module package.
 */
class OkModule extends AbstractModule implements ProvisioningModule
{
    public function configSchema(): array
    {
        return [
            'fields' => [
                ['key' => 'greeting', 'label' => 'Greeting', 'type' => 'text', 'default' => 'hello'],
                ['key' => 'secret', 'label' => 'Secret', 'type' => 'password', 'encrypted' => true],
            ],
        ];
    }

    public function provision(ServiceInstance $service, array $config): ProvisioningResult
    {
        return ProvisioningResult::ok('provisioned');
    }

    public function suspend(ServiceInstance $service, array $config): ProvisioningResult
    {
        return ProvisioningResult::ok('ok');
    }

    public function unsuspend(ServiceInstance $service, array $config): ProvisioningResult
    {
        return ProvisioningResult::ok('ok');
    }

    public function terminate(ServiceInstance $service, array $config): ProvisioningResult
    {
        return ProvisioningResult::ok('ok');
    }
}
