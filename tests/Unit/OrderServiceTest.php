<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OrderService;
    }

    public function test_happy_path_writes_history_for_each_hop(): void
    {
        $order = $this->makeOrder();

        $this->service->markPaid($order);
        $this->service->markProvisioning($order);
        $this->service->activate($order);

        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);

        $history = OrderStatusHistory::query()->orderBy('id')->get();
        $this->assertCount(3, $history);

        $this->assertSame(['pending', 'paid'], [$history[0]->from_status, $history[0]->to_status]);
        $this->assertSame(['paid', 'provisioning'], [$history[1]->from_status, $history[1]->to_status]);
        $this->assertSame(['provisioning', 'active'], [$history[2]->from_status, $history[2]->to_status]);
    }

    public function test_illegal_transition_throws(): void
    {
        $order = $this->makeOrder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Order cannot move from 'pending' to 'provisioning'");

        // pending→provisioning is not a legal edge (must go through paid).
        $this->service->transition($order, 'provisioning');
    }

    public function test_same_state_transition_is_idempotent(): void
    {
        $order = $this->makeOrder();

        $result = $this->service->transition($order, Order::STATUS_PENDING);

        $this->assertSame($order->id, $result->id);
        $this->assertSame(0, OrderStatusHistory::count(), 'no audit row for a no-op transition');
    }

    public function test_terminal_states_reject_all_transitions(): void
    {
        $order = $this->makeOrder();
        $this->service->cancel($order);

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertFalse($this->service->canTransition($order->fresh(), Order::STATUS_ACTIVE));
        $this->assertFalse($this->service->canTransition($order->fresh(), Order::STATUS_PAID));

        $this->expectException(InvalidArgumentException::class);
        $this->service->terminate($order->fresh());
    }

    public function test_legacy_activation_edge_still_works(): void
    {
        $order = $this->makeOrder();

        $this->service->activate($order);

        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);
        $history = OrderStatusHistory::where('order_id', $order->id)->sole();
        $this->assertSame(['pending', 'active'], [$history->from_status, $history->to_status]);
    }

    public function test_unknown_status_throws(): void
    {
        $order = $this->makeOrder();

        $this->expectException(InvalidArgumentException::class);
        $this->service->transition($order, 'bogus');
    }

    public function test_history_records_actor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $order = $this->makeOrder();
        $this->service->markPaid($order);

        $history = OrderStatusHistory::where('order_id', $order->id)->sole();
        $this->assertSame($user->id, $history->changed_by_user_id);
    }

    public function test_active_to_suspended_and_back(): void
    {
        $order = $this->makeOrder();
        $this->service->activate($order);
        $this->service->suspend($order->fresh());

        $this->assertSame(Order::STATUS_SUSPENDED, $order->fresh()->status);

        $this->service->activate($order->fresh());
        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create();
        $customer = Customer::create([
            'user_id' => $user->id,
            'company' => 'Test Corp',
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Shared Hosting Basic',
            'type' => 'shared_hosting',
            'price' => 100,
        ]);

        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
        ]);
    }
}
