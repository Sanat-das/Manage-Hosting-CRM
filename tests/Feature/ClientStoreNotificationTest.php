<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\OrderCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Placing an order from the storefront used to be completely silent: no event,
 * no email, and a draft invoice nobody was ever told about. The customer had no
 * way to learn there was something to pay, and no admin was told a sale had
 * come in — they had to notice the row in the orders list.
 */
class ClientStoreNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_placing_an_order_notifies_the_customer_and_the_admins(): void
    {
        Notification::fake();
        Queue::fake();

        $customer = $this->customerWithCartItem();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($customer->user)
            ->post(route('client.store.checkout.post'))
            ->assertRedirect();

        Notification::assertSentTo($customer, OrderCreatedNotification::class);
        Notification::assertSentTo($admin, OrderCreatedNotification::class);
    }

    public function test_the_notification_does_not_claim_a_pending_order_is_live(): void
    {
        $product = Product::create(['name' => 'Shared Hosting Basic', 'price' => 100.00, 'status' => 'active']);

        $order = Order::create([
            'customer_id' => $this->customer()->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-2026-00001',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
        ]);

        $pending = (new OrderCreatedNotification($order))->toArray($order->customer);
        $this->assertStringContainsString('awaiting payment', $pending['message']);
        $this->assertSame(Order::STATUS_PENDING, $pending['status']);

        $order->update(['status' => Order::STATUS_ACTIVE]);
        $active = (new OrderCreatedNotification($order->fresh()))->toArray($order->customer);
        $this->assertStringContainsString('activated', $active['message']);
    }

    public function test_placing_an_order_emails_the_confirmation_and_the_invoice(): void
    {
        Queue::fake();

        $this->template('order_confirmation', 'Order {{order_number}} confirmed', 'Hi {{customer_name}}, {{total}} — {{company_name}}');
        $this->template('invoice_created', 'Invoice {{invoice_no}}', 'Pay at {{pay_url}}');

        $customer = $this->customerWithCartItem();

        $this->actingAs($customer->user)
            ->post(route('client.store.checkout.post'))
            ->assertRedirect();

        $order = Order::sole();

        // Confirmation email, rendered from the shared variable map — the
        // branding placeholders the seeded HTML template uses must resolve, not
        // arrive as literal {{company_name}} text.
        Queue::assertPushed(SendEmail::class, function (SendEmail $job) use ($order, $customer) {
            return $job->toEmail === $customer->user->email
                && str_contains($job->subject, $order->order_number)
                && ! str_contains($job->body, '{{');
        });

        // Invoice email, and the invoice is no longer a draft the customer was
        // never told about.
        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => str_contains($job->subject, 'Invoice'));

        $this->assertSame(Invoice::STATUS_SENT, Invoice::sole()->status);
    }

    public function test_a_broken_mailer_never_costs_the_customer_their_order(): void
    {
        Event::fake([OrderCreated::class]);

        // No templates seeded at all: every send is skipped. The order and its
        // invoice must still exist and the customer must still reach the
        // confirmation page.
        $customer = $this->customerWithCartItem();

        $this->actingAs($customer->user)
            ->post(route('client.store.checkout.post'))
            ->assertRedirect();

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Invoice::count());
        Event::assertDispatched(OrderCreated::class);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    private function customer(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Store Corp',
            'status' => 'active',
        ]);
    }

    /**
     * A customer whose session cart holds one orderable product.
     */
    private function customerWithCartItem(): Customer
    {
        $customer = $this->customer();

        $product = Product::create([
            'name' => 'Shared Hosting Basic',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ]);

        $this->withSession(['cart' => [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'domain' => 'example.test',
        ]]]);

        return $customer->fresh();
    }

    private function template(string $name, string $subject, string $body): EmailTemplate
    {
        return EmailTemplate::create([
            'name' => $name,
            'subject' => $subject,
            'body' => $body,
            'status' => 'active',
        ]);
    }
}
