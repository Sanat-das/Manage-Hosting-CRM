<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Module\ProvisioningResult;
use App\Models\Customer;
use App\Models\Module;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Modules\ModuleManager;
use App\Services\OrderService;
use App\Services\Provisioning\ProvisioningDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithModuleFixtures;
use Tests\Fixtures\Modules\OkModule\OkModule;
use Tests\TestCase;

/**
 * advanceAfterPayment() must actually consult the product's provisioning
 * module instead of flipping the order straight to active.
 *
 * Before ProvisioningDispatcher existed, an automated module ('cpanel',
 * 'plesk', …) produced `provisioning -> active` with no module call at all, so
 * a paid order looked provisioned while nothing had been created remotely.
 */
class OrderProvisioningDispatchTest extends TestCase
{
    use InteractsWithModuleFixtures;
    use RefreshDatabase;

    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpModuleFixtures();
        $this->orders = app(OrderService::class);
    }

    public function test_provisioning_module_runs_and_activates_the_order(): void
    {
        $order = $this->makePaidOrder('cpanel');
        $this->linkProvisioningModule($order->product);

        $result = $this->orders->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);

        // The module was handed a real service instance for this order.
        $service = ServiceInstance::where('order_id', $order->id)->sole();
        $this->assertSame('active', $service->status);
        $this->assertNull($service->catalog_product_id);

        $this->assertDatabaseHas('provisioning_events', [
            'service_instance_id' => $service->id,
            'event_type' => 'provision',
            'event_status' => 'completed',
        ]);

        // The audit trail distinguishes a real provision from a bare activation.
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'provisioning',
            'to_status' => 'active',
            'notes' => 'Provisioned by module: provisioned',
        ]);
    }

    public function test_provisioning_failure_lands_the_order_in_failed(): void
    {
        $order = $this->makePaidOrder('cpanel');
        $this->linkProvisioningModule($order->product);

        // Swap the fixture's provider for one that reports failure. resolve()
        // goes through the container, so binding the provider class is enough.
        $this->app->bind(OkModule::class, fn () => new class extends OkModule
        {
            public function provision(ServiceInstance $service, array $config): ProvisioningResult
            {
                return ProvisioningResult::fail('panel refused the account');
            }
        });

        $result = $this->orders->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);

        $service = ServiceInstance::where('order_id', $order->id)->sole();
        $this->assertSame('pending', $service->status);

        $this->assertDatabaseHas('provisioning_events', [
            'service_instance_id' => $service->id,
            'event_type' => 'provision',
            'event_status' => 'failed',
        ]);
    }

    public function test_module_exception_is_isolated_and_fails_the_order(): void
    {
        $order = $this->makePaidOrder('cpanel');
        $this->linkProvisioningModule($order->product);

        $this->app->bind(OkModule::class, fn () => new class extends OkModule
        {
            public function provision(ServiceInstance $service, array $config): ProvisioningResult
            {
                throw new \RuntimeException('connection timed out');
            }
        });

        $result = $this->orders->advanceAfterPayment($order);

        // A throwing module must never escape into the payment path.
        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);
        $this->assertDatabaseHas('provisioning_events', [
            'event_type' => 'provision',
            'event_status' => 'failed',
        ]);
    }

    public function test_order_with_no_installed_module_still_activates_but_records_the_gap(): void
    {
        // 'cpanel' with nothing installed: the pre-existing behaviour (local
        // hosting/billing records + active) is preserved, but the event row now
        // makes it visible that no remote account was created.
        $order = $this->makePaidOrder('cpanel');

        $result = $this->orders->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);
        $this->assertSame(0, ServiceInstance::count());

        $event = ProvisioningEvent::sole();
        $this->assertSame('provision', $event->event_type);
        $this->assertSame('pending', $event->event_status);
        $this->assertNull($event->service_instance_id);
        $this->assertSame('no_provisioning_module', $event->payload['reason']);
        $this->assertSame($order->id, $event->payload['order_id']);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'to_status' => 'active',
            'notes' => 'Activated without a provisioning module — no remote account was created',
        ]);
    }

    public function test_manual_product_never_provisions_even_with_a_module_linked(): void
    {
        // 'manual' is an operator opt-out and must win over a linked
        // provisioning-capable module — otherwise the service would be
        // provisioned while the order sat waiting in 'provisioning'.
        $order = $this->makePaidOrder('manual');
        $this->linkProvisioningModule($order->product);

        $result = $this->orders->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_PROVISIONING, $result->fresh()->status);
        $this->assertSame(0, ServiceInstance::count());
        $this->assertDatabaseHas('provisioning_events', [
            'event_type' => 'provision',
            'event_status' => 'pending',
            'service_instance_id' => null,
        ]);
    }

    public function test_module_resolution_prefers_the_enabled_product_link(): void
    {
        $dispatcher = app(ProvisioningDispatcher::class);
        $order = $this->makePaidOrder('cpanel');

        $this->assertNull($dispatcher->moduleFor($order->product));

        $module = $this->linkProvisioningModule($order->product);
        $this->assertSame($module->id, $dispatcher->moduleFor($order->product->fresh())->id);

        // A disabled link is not a provisioning route.
        ProductModule::where('product_id', $order->product->id)->update(['enabled' => false]);
        $this->assertNull($dispatcher->moduleFor($order->product->fresh()));
    }

    // ─────────────────────────── helpers ───────────────────────────

    private function linkProvisioningModule(Product $product): Module
    {
        $manager = app(ModuleManager::class);
        $manager->reconcile();

        $module = $manager->find('ok-module');
        $manager->activate($module);

        ProductModule::create([
            'product_id' => $product->id,
            'module_id' => $module->id,
            'enabled' => true,
            'config' => ['greeting' => 'hi'],
        ]);

        return $module->fresh();
    }

    private function makePaidOrder(string $provisioningModule): Order
    {
        $user = User::factory()->create();
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $product = Product::create([
            'name' => 'Hosting '.$provisioningModule,
            'price' => 100,
            'provisioning_module' => $provisioningModule,
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
        ]);

        return $this->orders->markPaid($order);
    }
}
