<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderEmailService;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The client portal's order pages.
 *
 * The seeded order_confirmation email has always shipped a "View Order"
 * button, and it pointed at /client/orders — a route that did not exist, so
 * every customer who clicked it landed on a 404. Nothing else in the portal
 * showed an order either: the storefront's confirmation page was a one-shot
 * redirect target, and an order placed by an admin was invisible.
 */
class ClientOrderPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_view_order_button_in_the_confirmation_email_reaches_a_real_page(): void
    {
        Queue::fake();

        $this->seed(EmailTemplateSeeder::class);

        $customer = $this->customer();
        $order = $this->order($customer);

        $this->assertTrue(app(OrderEmailService::class)->send($order));

        $html = null;
        Queue::assertPushed(SendEmail::class, function (SendEmail $job) use (&$html) {
            $html = $job->htmlBody;

            return true;
        });

        $this->assertNotNull($html, 'The confirmation email was queued without an HTML body.');

        // The href the customer actually clicks, taken out of the rendered
        // template rather than assumed.
        preg_match('/<a\s[^>]*href="([^"]+)"[^>]*>\s*View Order\s*<\/a>/i', (string) $html, $matches);
        $this->assertNotEmpty($matches, 'The confirmation email has no "View Order" link.');

        $href = $matches[1];
        $this->assertSame(route('client.orders.show', $order->id), $href);
        $this->assertStringNotContainsString('{{', $href, 'The link still carries an unrendered placeholder.');

        $this->actingAs($customer->user)
            ->get($href)
            ->assertOk()
            ->assertSee($order->order_number);
    }

    public function test_the_orders_page_lists_only_the_customers_own_orders(): void
    {
        $customer = $this->customer();
        $mine = $this->order($customer);

        $theirs = $this->order($this->customer('other@example.com'));

        $this->actingAs($customer->user)
            ->get(route('client.orders.index'))
            ->assertOk()
            ->assertSee($mine->order_number)
            ->assertDontSee($theirs->order_number);
    }

    public function test_the_order_page_shows_what_was_bought_and_its_invoice(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $order->product_id,
            'product_name' => 'Shared Hosting',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'config_options' => ['options' => [['group' => 'RAM', 'selected' => '8 GB']]],
        ]);

        $invoice = Invoice::create([
            'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $this->actingAs($customer->user)
            ->get(route('client.orders.show', $order->id))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Shared Hosting')
            ->assertSee('8 GB')
            ->assertSee($invoice->invoice_no)
            // An open invoice is payable from here.
            ->assertSee(route('client.invoices.pay', $invoice->id));
    }

    public function test_another_customers_order_is_a_404(): void
    {
        $customer = $this->customer();
        $theirs = $this->order($this->customer('other@example.com'));

        // Scoped through the customer relation, so it is not found rather than
        // forbidden — the response does not confirm the order exists.
        $this->actingAs($customer->user)
            ->get(route('client.orders.show', $theirs->id))
            ->assertNotFound();
    }

    public function test_the_order_page_does_not_leak_the_internal_audit_notes(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, Order::STATUS_ACTIVE);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => Order::STATUS_PENDING,
            'to_status' => Order::STATUS_ACTIVE,
            'notes' => 'Chased twice, card declined, watch this one',
        ]);

        $this->actingAs($customer->user)
            ->get(route('client.orders.show', $order->id))
            ->assertOk()
            // The progress the customer is entitled to see...
            ->assertSee('Progress')
            // ...without the staff commentary attached to the same row.
            ->assertDontSee('Chased twice');
    }

    public function test_a_customer_with_no_orders_is_pointed_at_the_store(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer->user)
            ->get(route('client.orders.index'))
            ->assertOk()
            ->assertSee("You haven't placed any orders yet.", false)
            ->assertSee('Browse the Store');
    }

    public function test_neither_page_leaks_a_blade_directive_into_the_output(): void
    {
        // A misplaced directive (`@endcan@stop`) compiles without error and
        // then prints itself to the customer, so the guard has to be on the
        // rendered HTML rather than on the compiled view.
        $customer = $this->customer();
        $order = $this->order($customer);

        $pages = [
            $this->actingAs($customer->user)->get(route('client.orders.index'))->getContent(),
            $this->actingAs($customer->user)->get(route('client.orders.show', $order->id))->getContent(),
        ];

        foreach ($pages as $html) {
            foreach (['@stop', '@endsection', '@endif', '@endforelse', '{{', '@php'] as $directive) {
                $this->assertStringNotContainsString($directive, (string) $html);
            }
        }
    }

    public function test_a_guest_cannot_read_orders(): void
    {
        $order = $this->order($this->customer());

        // bootstrap/app.php sends portal guests to the client login page.
        $this->get(route('client.orders.index'))->assertRedirect(route('client.login'));
        $this->get(route('client.orders.show', $order->id))->assertRedirect(route('client.login'));
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    private function customer(?string $email = null): Customer
    {
        $user = User::factory()->create($email !== null ? ['email' => $email] : []);

        return Customer::create(['user_id' => $user->id, 'status' => 'active']);
    }

    private function order(Customer $customer, string $status = Order::STATUS_PENDING): Order
    {
        $product = Product::create([
            'name' => 'Shared Hosting',
            'price' => 100,
            'provisioning_module' => 'manual',
        ]);

        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => $status,
        ]);
    }
}
