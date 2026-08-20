<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Events\OrderPaid;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\NotificationPreference;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T3.3 acceptance: event/listener wiring dispatches database notifications,
 * gated through NotificationPreferenceService.
 */
class NotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);
    }

    private function makeOrderFor(Customer $customer): Order
    {
        $product = Product::create([
            'name' => 'Shared Hosting',
            'price' => 100,
            'status' => 'active',
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

    // ── (a) OrderPaid → DB notification for the customer ─────────────

    public function test_order_paid_dispatches_database_notification_to_customer(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrderFor($customer);

        OrderPaid::dispatch($order);

        $customer->refresh();

        $this->assertSame(1, $customer->unreadNotifications->count());
        $this->assertDatabaseCount('notifications', 1);

        $notification = $customer->unreadNotifications->first();
        $this->assertSame($order->id, $notification->data['order_id']);
    }

    // ── (b) disabled preference suppresses the notification ──────────

    public function test_disabled_preference_suppresses_order_paid_notification(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrderFor($customer);

        NotificationPreference::create([
            'preferrable_type' => Customer::class,
            'preferrable_id' => $customer->id,
            'type' => 'order.paid',
            'channel' => NotificationPreference::CHANNEL_DATABASE,
            'enabled' => false,
        ]);

        OrderPaid::dispatch($order);

        $customer->refresh();

        $this->assertSame(0, $customer->unreadNotifications->count());
        $this->assertDatabaseCount('notifications', 0);
    }

    // ── (a2) OrderCreated → DB notification for the customer ─────────

    public function test_order_created_dispatches_database_notification_to_customer(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrderFor($customer);

        OrderCreated::dispatch($order);

        $customer->refresh();

        $this->assertSame(1, $customer->unreadNotifications->count());
        $this->assertDatabaseCount('notifications', 1);

        $notification = $customer->unreadNotifications->first();
        $this->assertSame($order->id, $notification->data['order_id']);
        $this->assertSame('order.created', $notification->data['type']);
    }

    public function test_disabled_preference_suppresses_order_created_notification(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrderFor($customer);

        NotificationPreference::create([
            'preferrable_type' => Customer::class,
            'preferrable_id' => $customer->id,
            'type' => 'order.created',
            'channel' => NotificationPreference::CHANNEL_DATABASE,
            'enabled' => false,
        ]);

        OrderCreated::dispatch($order);

        $customer->refresh();

        $this->assertSame(0, $customer->unreadNotifications->count());
        $this->assertDatabaseCount('notifications', 0);
    }

    // ── (c) DomainExpiryCheckCommand → customer notification ─────────

    public function test_domain_expiry_check_dispatches_notification_to_customer(): void
    {
        $customer = $this->makeCustomer();

        Domain::create([
            'customer_id' => $customer->id,
            'name' => 'expiring.example.com',
            'status' => 'active',
            'expiry_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->artisan('domains:expiry-check', ['--days' => 30])->assertExitCode(0);

        $customer->refresh();

        $this->assertSame(1, $customer->unreadNotifications->count());
        $this->assertSame('expiring.example.com', $customer->unreadNotifications->first()->data['domain_name']);
    }

    // ── (d) idempotence: running twice → exactly one notification ────

    public function test_domain_expiry_check_is_idempotent_per_domain(): void
    {
        $customer = $this->makeCustomer();

        Domain::create([
            'customer_id' => $customer->id,
            'name' => 'expiring.example.com',
            'status' => 'active',
            'expiry_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->artisan('domains:expiry-check', ['--days' => 30])->assertExitCode(0);
        $this->artisan('domains:expiry-check', ['--days' => 30])->assertExitCode(0);

        $customer->refresh();

        $this->assertSame(1, $customer->unreadNotifications->count());
        $this->assertDatabaseCount('notifications', 1);
    }
}
