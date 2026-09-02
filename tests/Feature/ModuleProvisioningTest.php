<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\Module;
use App\Models\ModuleLog;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithModuleFixtures;
use Tests\TestCase;

/**
 * Capability dispatch: provisioning runs inside the RunModuleCapability job
 * (sync queue in tests) and every outcome — success, unknown method, or an
 * unsupported capability — is logged to module_log without ever bubbling an
 * exception back into the caller.
 */
class ModuleProvisioningTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithModuleFixtures;

    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpModuleFixtures();
        $this->manager = app(ModuleManager::class);
    }

    private function makeServiceInstance(): ServiceInstance
    {
        $user = User::factory()->create();

        $customer = Customer::create(['user_id' => $user->id]);
        $product = CatalogProduct::create([
            'sku' => 'test-'.uniqid(),
            'name' => 'Test Product',
        ]);

        return ServiceInstance::create([
            'customer_id' => $customer->id,
            'catalog_product_id' => $product->id,
            'service_tag' => 'svc-'.uniqid(),
            'username' => 'svcuser',
        ]);
    }

    private function activatedOkModule(): Module
    {
        $this->manager->reconcile();
        $module = $this->manager->find('ok-module');
        $this->manager->activate($module);

        return $module;
    }

    public function test_dispatch_capability_runs_provision_and_logs(): void
    {
        $module = $this->activatedOkModule();
        $service = $this->makeServiceInstance();

        $this->manager->dispatchCapability($module, 'provisioning', 'provision', $service, ['greeting' => 'hi']);

        $this->assertDatabaseHas('module_log', [
            'module_id' => $module->id,
            'event' => 'provision',
            'status' => 'success',
            'service_instance_id' => $service->id,
        ]);
    }

    public function test_dispatch_capability_logs_failure_for_unknown_method(): void
    {
        $module = $this->activatedOkModule();
        $service = $this->makeServiceInstance();

        $this->manager->dispatchCapability($module, 'provisioning', 'doesNotExist', $service, []);

        $log = ModuleLog::where('module_id', $module->id)->where('event', 'doesNotExist')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('doesNotExist', (string) $log->error);
        $this->assertSame($service->id, $log->service_instance_id);
    }

    public function test_dispatch_capability_logs_unsupported_capability(): void
    {
        $this->manager->reconcile();
        $module = $this->manager->find('crash-module');
        $service = $this->makeServiceInstance();

        $this->manager->dispatchCapability($module, 'provisioning', 'provision', $service, []);

        $this->assertDatabaseHas('module_log', [
            'module_id' => $module->id,
            'event' => 'capability',
            'status' => 'failed',
            'error' => 'capability not supported',
            'service_instance_id' => $service->id,
        ]);
    }

    public function test_capabilities_detection(): void
    {
        $this->manager->reconcile();

        $this->assertSame(['provisioning'], $this->manager->capabilities($this->manager->find('ok-module')));
        $this->assertSame([], $this->manager->capabilities($this->manager->find('crash-module')));
    }
}
