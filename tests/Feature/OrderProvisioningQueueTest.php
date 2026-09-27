<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Integrations\ProvisioningResult;
use App\Jobs\RunOrderLifecycleVerb;
use App\Jobs\RunOrderProvisioning;
use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Module;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\HostingService;
use App\Services\Modules\ModuleManager;
use App\Services\OrderService;
use App\Services\Provisioning\ProvisioningDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithModuleFixtures;
use Tests\Fixtures\Modules\OkModule\OkModule;
use Tests\TestCase;

/**
 * Order provisioning runs queued, not inline in the web request.
 *
 * advanceAfterPayment() and the pending/provisioning/failed -> active hook
 * only move the order to `provisioning` (or leave it `active`) and push
 * RunOrderProvisioning; the suspend/unsuspend/terminate hops push
 * RunOrderLifecycleVerb. With the real sync queue the jobs run inline and
 * land exactly the end states the inline path produced.
 */
class OrderProvisioningQueueTest extends TestCase
{
    use InteractsWithModuleFixtures;
    use RefreshDatabase;

    /** @var list<string> module verbs the fixture module was asked to perform */
    public static array $calls = [];

    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpModuleFixtures();
        $this->orders = app(OrderService::class);
        self::$calls = [];
    }

    public function test_paying_an_auto_provision_order_queues_the_build_without_running_it_inline(): void
    {
        Queue::fake();

        $order = $this->makePaidOrder('cpanel');
        $this->linkRecordingModule($order->product);

        $result = $this->orders->advanceAfterPayment($order);

        // The order waits in provisioning until the job finishes; nothing ran.
        $this->assertSame(Order::STATUS_PROVISIONING, $result->fresh()->status);
        $this->assertSame([], self::$calls);
        $this->assertSame(0, ServiceInstance::where('order_id', $order->id)->count());
        $this->assertSame(0, ProvisioningEvent::count());

        Queue::assertPushedOn('provisioning', RunOrderProvisioning::class, function (RunOrderProvisioning $job) use ($order): bool {
            return $job->orderId === $order->id
                && $job->tries === 1
                && $job->timeout === 1800;
        });
    }

    public function test_activating_a_pending_order_queues_the_build(): void
    {
        Queue::fake();

        $order = $this->makePendingOrder('cpanel');
        $this->linkRecordingModule($order->product);

        $result = $this->orders->activate($order);

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);
        $this->assertSame([], self::$calls);

        Queue::assertPushedOn('provisioning', RunOrderProvisioning::class, function (RunOrderProvisioning $job) use ($order): bool {
            return $job->orderId === $order->id;
        });
    }

    public function test_manual_compute_order_does_not_queue_a_build(): void
    {
        Queue::fake();

        $product = Product::create([
            'name' => 'HyperV manual',
            'price' => 50,
            'provisioning_module' => 'hyperv',
        ]);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'config' => ['cpu' => 2, 'ram' => 2048, 'disk' => 50],
        ]);

        $order = $this->makePaidOrderForProduct($product);

        $result = $this->orders->advanceAfterPayment($order);

        // Manual compute activates immediately with no VM build queued.
        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);
        Queue::assertNotPushed(RunOrderProvisioning::class);
        $this->assertSame(0, ServiceInstance::where('order_id', $order->id)->count());

        $event = ProvisioningEvent::where('event_type', 'provision')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('awaiting_manual_vm', $event->payload['reason'] ?? null);
    }

    public function test_lifecycle_hops_queue_the_verb_without_calling_the_module_inline(): void
    {
        $order = $this->activeProvisionedOrder();

        Queue::fake();

        $this->orders->suspend($order->fresh(), 'Non-payment');

        $this->assertSame(Order::STATUS_SUSPENDED, $order->fresh()->status);
        $this->assertSame([], self::$calls);
        Queue::assertPushedOn('provisioning', RunOrderLifecycleVerb::class, function (RunOrderLifecycleVerb $job) use ($order): bool {
            return $job->orderId === $order->id
                && $job->verb === 'suspend'
                && $job->reason === 'Non-payment'
                && $job->tries === 1
                && $job->timeout === 600;
        });

        $this->orders->activate($order->fresh(), 'Paid up');

        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertSame([], self::$calls);
        Queue::assertPushedOn('provisioning', RunOrderLifecycleVerb::class, function (RunOrderLifecycleVerb $job) use ($order): bool {
            return $job->orderId === $order->id && $job->verb === 'unsuspend';
        });

        $this->orders->terminate($order->fresh(), 'Customer left');

        $this->assertSame(Order::STATUS_TERMINATED, $order->fresh()->status);
        $this->assertSame([], self::$calls);
        Queue::assertPushedOn('provisioning', RunOrderLifecycleVerb::class, function (RunOrderLifecycleVerb $job) use ($order): bool {
            return $job->orderId === $order->id && $job->verb === 'terminate';
        });
    }

    public function test_sync_queue_completes_the_build_to_active(): void
    {
        $order = $this->makePaidOrder('cpanel');
        $this->linkProvisioningModule($order->product);

        $result = $this->orders->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);

        $service = ServiceInstance::where('order_id', $order->id)->sole();
        $this->assertSame('active', $service->status);

        $this->assertDatabaseHas('provisioning_events', [
            'service_instance_id' => $service->id,
            'event_type' => 'provision',
            'event_status' => 'completed',
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'provisioning',
            'to_status' => 'active',
            'notes' => 'Provisioned by module: provisioned',
        ]);
    }

    public function test_sync_queue_lands_failed_when_the_module_refuses(): void
    {
        $order = $this->makePaidOrder('cpanel');
        $this->linkProvisioningModule($order->product);

        $this->app->bind(OkModule::class, fn () => new class extends OkModule
        {
            public function provision(ServiceInstance $service, array $config): ProvisioningResult
            {
                return ProvisioningResult::fail('panel refused the account');
            }
        });

        $result = $this->orders->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_FAILED, $result->fresh()->status);
        $this->assertDatabaseHas('provisioning_events', [
            'event_type' => 'provision',
            'event_status' => 'failed',
        ]);
    }

    public function test_sync_queue_runs_queued_lifecycle_verbs_inline(): void
    {
        $order = $this->activeProvisionedOrder();

        $this->orders->suspend($order, 'Non-payment');

        $this->assertSame(Order::STATUS_SUSPENDED, $order->fresh()->status);
        $this->assertContains('suspend', self::$calls);
        $this->assertSame('suspended', ServiceInstance::where('order_id', $order->id)->sole()->status);
        $this->assertDatabaseHas('provisioning_events', [
            'event_type' => 'suspend',
            'event_status' => 'completed',
        ]);
    }

    public function test_jobs_never_throw_on_missing_orders_or_bad_verbs(): void
    {
        $order = $this->makePaidOrder('cpanel');

        (new RunOrderProvisioning(999999))->handle(app(OrderService::class), app(ProvisioningDispatcher::class));
        (new RunOrderLifecycleVerb(999999, 'suspend', 'gone'))->handle(app(ProvisioningDispatcher::class));
        (new RunOrderLifecycleVerb($order->id, 'reboot'))->handle(app(ProvisioningDispatcher::class));

        (new RunOrderProvisioning(999999))->failed(new \RuntimeException('boom'));
        (new RunOrderLifecycleVerb($order->id, 'suspend'))->failed(new \RuntimeException('boom'));

        $this->assertSame(0, ProvisioningEvent::count());
        $this->assertTrue(true);
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
            'module_slug' => $module->slug,
            'enabled' => true,
            'config' => ['greeting' => 'hi'],
        ]);

        return $module->fresh();
    }

    private function linkRecordingModule(Product $product): Module
    {
        $module = $this->linkProvisioningModule($product);

        $this->app->bind(OkModule::class, fn () => new class extends OkModule
        {
            public function provision(ServiceInstance $service, array $config): ProvisioningResult
            {
                OrderProvisioningQueueTest::$calls[] = 'provision';

                return ProvisioningResult::ok('provisioned');
            }

            public function suspend(ServiceInstance $service, array $config): ProvisioningResult
            {
                OrderProvisioningQueueTest::$calls[] = 'suspend';

                return ProvisioningResult::ok('suspended');
            }

            public function unsuspend(ServiceInstance $service, array $config): ProvisioningResult
            {
                OrderProvisioningQueueTest::$calls[] = 'unsuspend';

                return ProvisioningResult::ok('unsuspended');
            }

            public function terminate(ServiceInstance $service, array $config): ProvisioningResult
            {
                OrderProvisioningQueueTest::$calls[] = 'terminate';

                return ProvisioningResult::ok('terminated');
            }
        });

        return $module;
    }

    /**
     * An active order with a provisioned service instance and a live local
     * hosting account. Built on the sync queue so the queued build runs
     * inline, exactly as the endpoints see it.
     */
    private function activeProvisionedOrder(): Order
    {
        $order = $this->makePendingOrder();

        $this->linkRecordingModule($order->product);

        HostingAccount::create([
            'customer_id' => $order->customer_id,
            'product_id' => $order->product_id,
            'order_id' => $order->id,
            'domain' => 'example.test',
            'status' => HostingService::STATUS_ACTIVE,
        ]);

        $this->orders->markPaid($order);
        $this->orders->advanceAfterPayment($order->fresh());

        $order = $order->fresh();
        $this->assertSame(Order::STATUS_ACTIVE, $order->status);
        self::$calls = [];

        return $order;
    }

    private function makePendingOrder(string $provisioningModule = 'cpanel'): Order
    {
        $user = User::factory()->create();
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $product = Product::create([
            'name' => 'Hosting '.$provisioningModule,
            'price' => 100,
            'provisioning_module' => $provisioningModule,
        ]);

        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
            'domain_name' => 'example.test',
        ]);
    }

    private function makePaidOrder(string $provisioningModule): Order
    {
        return $this->orders->markPaid($this->makePendingOrder($provisioningModule));
    }

    private function makePaidOrderForProduct(Product $product): Order
    {
        $user = User::factory()->create();
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
            'domain_name' => 'example.test',
        ]);

        return $this->orders->markPaid($order);
    }
}
