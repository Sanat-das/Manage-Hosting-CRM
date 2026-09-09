<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Module\ProvisioningResult;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithModuleFixtures;
use Tests\Fixtures\Modules\OkModule\OkModule;
use Tests\TestCase;

/**
 * Ending an order has to end the service.
 *
 * Suspension, reactivation and termination used to change nothing but the
 * order's status column: the modules had implemented suspend()/unsuspend()/
 * terminate() since they were written and nothing ever called them, so a
 * suspended order left a live control-panel account serving the customer's
 * site and a terminated one left it running for good.
 */
class OrderLifecycleProvisioningTest extends TestCase
{
    use InteractsWithModuleFixtures;
    use RefreshDatabase;

    private OrderService $orders;

    /** @var list<string> module verbs the fixture module was asked to perform */
    public static array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpModuleFixtures();
        $this->orders = app(OrderService::class);
        self::$calls = [];
    }

    public function test_suspending_an_order_suspends_the_remote_service(): void
    {
        $order = $this->activeProvisionedOrder();

        $this->orders->suspend($order, 'Non-payment');

        $this->assertSame(Order::STATUS_SUSPENDED, $order->fresh()->status);
        $this->assertContains('suspend', self::$calls);

        $service = ServiceInstance::where('order_id', $order->id)->sole();
        $this->assertSame('suspended', $service->status);

        $this->assertDatabaseHas('provisioning_events', [
            'service_instance_id' => $service->id,
            'event_type' => 'suspend',
            'event_status' => 'completed',
        ]);

        // The local hosting record follows the order too.
        $this->assertSame(
            HostingService::STATUS_SUSPENDED,
            $order->hostingAccount()->first()->status,
        );
    }

    public function test_reactivating_a_suspended_order_unsuspends_the_remote_service(): void
    {
        $order = $this->activeProvisionedOrder();
        $this->orders->suspend($order);
        self::$calls = [];

        $this->orders->activate($order->fresh(), 'Paid up');

        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertSame(['unsuspend'], self::$calls);

        $this->assertSame('active', ServiceInstance::where('order_id', $order->id)->sole()->status);
        $this->assertSame(
            HostingService::STATUS_ACTIVE,
            $order->hostingAccount()->first()->status,
        );

        $this->assertDatabaseHas('provisioning_events', [
            'event_type' => 'unsuspend',
            'event_status' => 'completed',
        ]);
    }

    public function test_terminating_an_order_destroys_the_remote_service(): void
    {
        $order = $this->activeProvisionedOrder();

        $this->orders->terminate($order, 'Customer left');

        $this->assertSame(Order::STATUS_TERMINATED, $order->fresh()->status);
        $this->assertSame(['terminate'], self::$calls);

        $this->assertSame('terminated', ServiceInstance::where('order_id', $order->id)->sole()->status);
        $this->assertSame(
            HostingService::STATUS_TERMINATED,
            $order->hostingAccount()->first()->status,
        );

        $this->assertDatabaseHas('provisioning_events', [
            'event_type' => 'terminate',
            'event_status' => 'completed',
        ]);
    }

    public function test_cancelling_a_provisioned_order_also_releases_the_service(): void
    {
        // active -> cancelled is a legal hop, and it ends the order just as
        // surely as a termination does. Leaving the panel account running
        // would make "cancelled" mean "still serving, still costing a licence".
        $order = $this->activeProvisionedOrder();

        $this->orders->cancel($order, 'Cancelled by request');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(['terminate'], self::$calls);
        $this->assertSame('terminated', ServiceInstance::where('order_id', $order->id)->sole()->status);
    }

    public function test_a_module_refusing_records_the_divergence_without_blocking_the_operator(): void
    {
        $order = $this->activeProvisionedOrder();

        $this->app->bind(OkModule::class, fn () => new class extends OkModule
        {
            public function suspend(ServiceInstance $service, array $config): ProvisioningResult
            {
                return ProvisioningResult::fail('panel unreachable');
            }
        });

        $this->orders->suspend($order, 'Non-payment');

        // The operator's decision stands — the order and the local account are
        // suspended — but the failed event row is the cue that the panel was
        // never told.
        $this->assertSame(Order::STATUS_SUSPENDED, $order->fresh()->status);
        $this->assertSame(
            HostingService::STATUS_SUSPENDED,
            $order->hostingAccount()->first()->status,
        );
        $this->assertSame('active', ServiceInstance::where('order_id', $order->id)->sole()->status);

        $this->assertDatabaseHas('provisioning_events', [
            'event_type' => 'suspend',
            'event_status' => 'failed',
        ]);
    }

    public function test_a_throwing_module_never_escapes_into_the_status_change(): void
    {
        $order = $this->activeProvisionedOrder();

        $this->app->bind(OkModule::class, fn () => new class extends OkModule
        {
            public function terminate(ServiceInstance $service, array $config): ProvisioningResult
            {
                throw new \RuntimeException('connection reset');
            }
        });

        $this->orders->terminate($order, 'Customer left');

        $this->assertSame(Order::STATUS_TERMINATED, $order->fresh()->status);
        $this->assertDatabaseHas('provisioning_events', [
            'event_type' => 'terminate',
            'event_status' => 'failed',
        ]);
    }

    public function test_cancelling_an_unprovisioned_order_is_a_silent_no_op(): void
    {
        // Nothing was ever created remotely, so there is nothing to end and
        // nothing worth logging.
        $order = $this->makeOrder('cpanel');

        $this->orders->cancel($order, 'Changed their mind');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame([], self::$calls);
        $this->assertSame(0, ProvisioningEvent::count());
    }

    public function test_an_order_with_a_service_but_no_module_records_the_gap(): void
    {
        // The service exists (it was provisioned when a module was installed,
        // or by hand) but no module can be resolved now: local state says
        // suspended while the panel was never told, which an operator must see.
        $order = $this->activeProvisionedOrder();
        ProductModule::where('product_id', $order->product_id)->update(['enabled' => false]);
        Module::query()->update(['status' => Module::STATUS_DISABLED]);

        $this->orders->suspend($order, 'Non-payment');

        $this->assertSame(Order::STATUS_SUSPENDED, $order->fresh()->status);
        $this->assertSame([], self::$calls);

        $event = ProvisioningEvent::where('event_type', 'suspend')->sole();
        $this->assertSame('pending', $event->event_status);
        $this->assertSame('no_provisioning_module', $event->payload['reason']);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /**
     * An active order with a provisioned service instance, a live local hosting
     * account, and a module wired up that records every verb it is asked for.
     */
    private function activeProvisionedOrder(): Order
    {
        $order = $this->makeOrder('cpanel');

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

    private function makeOrder(string $provisioningModule): Order
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

    private function linkRecordingModule(Product $product): Module
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

        $this->app->bind(OkModule::class, fn () => new class extends OkModule
        {
            public function suspend(ServiceInstance $service, array $config): ProvisioningResult
            {
                OrderLifecycleProvisioningTest::$calls[] = 'suspend';

                return ProvisioningResult::ok('suspended');
            }

            public function unsuspend(ServiceInstance $service, array $config): ProvisioningResult
            {
                OrderLifecycleProvisioningTest::$calls[] = 'unsuspend';

                return ProvisioningResult::ok('unsuspended');
            }

            public function terminate(ServiceInstance $service, array $config): ProvisioningResult
            {
                OrderLifecycleProvisioningTest::$calls[] = 'terminate';

                return ProvisioningResult::ok('terminated');
            }
        });

        return $module->fresh();
    }
}
