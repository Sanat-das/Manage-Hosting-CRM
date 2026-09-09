<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\User;
use App\Services\Payments\Drivers\StripeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The gateway-to-server confirmation path.
 *
 * Before this endpoint existed a redirect payment was only ever confirmed by
 * the customer's browser reaching the return URL: close the tab at the gateway
 * and the payment stayed 'pending' forever, the invoice unpaid and the order
 * unprovisioned, with nothing that would ever reconcile it.
 */
class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    public function test_signed_webhook_settles_the_payment_and_advances_the_order(): void
    {
        [$invoice, $payment, $order] = $this->pendingStripePayment();

        $this->stripeSays('succeeded', 10000);

        $this->postWebhook($this->stripeEvent('pi_test_1'))
            ->assertOk()
            ->assertJson(['result' => 'settled']);

        $payment->refresh();
        $this->assertSame('completed', $payment->status);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEqualsWithDelta(100.00, (float) $invoice->paid_amount, 0.001);

        // The webhook is a real payment, so it must drive the order lifecycle
        // exactly as a browser return does. The product provisions manually,
        // so the order lands awaiting an operator rather than active.
        $this->assertSame(Order::STATUS_PROVISIONING, $order->fresh()->status);

        // The pending row is settled in place, not duplicated beside a new one.
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
    }

    public function test_repeated_delivery_does_not_credit_the_invoice_twice(): void
    {
        [$invoice, $payment] = $this->pendingStripePayment();

        $this->stripeSays('succeeded', 10000);

        $this->postWebhook($this->stripeEvent('pi_test_1'))->assertOk();
        $this->postWebhook($this->stripeEvent('pi_test_1'))
            ->assertOk()
            ->assertJson(['result' => 'already_processed']);

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertEqualsWithDelta(100.00, (float) $invoice->fresh()->paid_amount, 0.001);
    }

    public function test_a_forged_delivery_is_rejected_and_records_nothing(): void
    {
        [$invoice, $payment] = $this->pendingStripePayment();

        Http::fake();

        $body = json_encode($this->stripeEvent('pi_test_1'), JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        )->assertStatus(400)->assertJson(['result' => 'invalid_signature']);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('sent', $invoice->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_the_body_is_not_trusted_the_gateway_is_asked(): void
    {
        [$invoice, $payment] = $this->pendingStripePayment();

        // A perfectly signed delivery claiming success, but the gateway's own
        // API says the intent has not succeeded. No money may be recorded.
        $this->stripeSays('requires_payment_method', 10000);

        $this->postWebhook($this->stripeEvent('pi_test_1'))
            ->assertStatus(202)
            ->assertJson(['result' => 'not_verified']);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_a_confirmed_underpayment_leaves_the_invoice_partial(): void
    {
        [$invoice, $payment] = $this->pendingStripePayment();

        // The gateway's figure wins over what we hoped to collect.
        $this->stripeSays('succeeded', 4000);

        $this->postWebhook($this->stripeEvent('pi_test_1'))->assertOk();

        $invoice->refresh();
        $this->assertSame('partial', $invoice->status);
        $this->assertEqualsWithDelta(40.00, (float) $invoice->paid_amount, 0.001);
    }

    public function test_unknown_reference_is_accepted_without_a_retry(): void
    {
        $this->pendingStripePayment();

        Http::fake();

        $this->postWebhook($this->stripeEvent('pi_someone_else'))
            ->assertStatus(202)
            ->assertJson(['result' => 'unknown_reference']);
    }

    public function test_an_event_we_do_not_act_on_is_ignored(): void
    {
        $this->pendingStripePayment();

        Http::fake();

        $event = $this->stripeEvent('pi_test_1');
        $event['type'] = 'payment_intent.created';

        $this->postWebhook($event)
            ->assertStatus(202)
            ->assertJson(['result' => 'ignored']);
    }

    public function test_a_gateway_without_a_webhook_secret_refuses_deliveries(): void
    {
        $this->pendingStripePayment(['secret_key' => 'sk_test_123']);

        $this->postWebhook($this->stripeEvent('pi_test_1'))->assertStatus(400);
    }

    public function test_unknown_gateway_is_a_404(): void
    {
        $this->postJson('/webhooks/payments/nope', [])->assertNotFound();
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /**
     * A customer with a sent invoice, an order behind it, and a pending Stripe
     * payment for the full amount — the state a redirect leaves behind.
     *
     * @return array{0: Invoice, 1: Payment, 2: Order}
     */
    private function pendingStripePayment(?array $credentials = null): array
    {
        $user = User::factory()->create();
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $product = Product::create([
            'name' => 'Shared Hosting',
            'price' => 100,
            'provisioning_module' => 'manual',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_PENDING,
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

        $gateway = PaymentGateway::create([
            'code' => 'stripe',
            'name' => 'Stripe',
            'driver' => StripeDriver::class,
            'mode' => 'test',
            'enabled' => true,
            'credentials' => $credentials ?? ['secret_key' => 'sk_test_123', 'webhook_secret' => self::SECRET],
        ]);

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'method' => 'stripe',
            'gateway_id' => (string) $gateway->id,
            'transaction_id' => 'pi_test_1',
            'status' => 'pending',
        ]);

        return [$invoice, $payment, $order];
    }

    private function stripeSays(string $status, int $amount): void
    {
        Http::fake([
            'api.stripe.com/*' => Http::response(
                ['id' => 'pi_test_1', 'status' => $status, 'amount' => $amount],
                200,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeEvent(string $intentId): array
    {
        return [
            'id' => 'evt_test_1',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => $intentId, 'object' => 'payment_intent']],
        ];
    }

    /**
     * POST a delivery signed the way Stripe signs one.
     *
     * @param  array<string, mixed>  $event
     */
    private function postWebhook(array $event): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        return $this->call(
            'POST',
            '/webhooks/payments/stripe',
            server: [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );
    }
}
