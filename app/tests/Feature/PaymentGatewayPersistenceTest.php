<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\User;
use Database\Seeders\PaymentGatewaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentGatewayPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_gateway_seeder_is_idempotent(): void
    {
        Artisan::call('db:seed', ['--class' => PaymentGatewaySeeder::class]);
        $this->assertDatabaseCount('payment_gateways', 5);

        Artisan::call('db:seed', ['--class' => PaymentGatewaySeeder::class]);
        $this->assertDatabaseCount('payment_gateways', 5);
        $this->assertSame(5, PaymentGateway::count());
        $this->assertSame(5, PaymentGateway::pluck('code')->unique()->count());
    }

    public function test_enabled_attribute_is_cast_to_boolean(): void
    {
        PaymentGateway::create([
            'code' => 'stripe',
            'name' => 'Stripe',
            'driver' => 'App\\Services\\Payments\\Drivers\\StripeDriver',
            'mode' => 'test',
            'enabled' => false,
        ]);

        $gateway = PaymentGateway::where('code', 'stripe')->firstOrFail();

        $this->assertIsBool($gateway->enabled);
        $this->assertFalse($gateway->enabled);
    }

    public function test_credentials_cast_round_trips_to_array(): void
    {
        $gateway = PaymentGateway::create([
            'code' => 'stripe',
            'name' => 'Stripe',
            'driver' => 'App\\Services\\Payments\\Drivers\\StripeDriver',
            'mode' => 'test',
            'enabled' => false,
            'credentials' => ['api_key' => 'sk_test_123', 'webhook_secret' => 'whsec_abc'],
        ]);

        $fresh = PaymentGateway::findOrFail($gateway->id);

        $this->assertIsArray($fresh->credentials);
        $this->assertSame('sk_test_123', $fresh->getCredential('api_key'));
        $this->assertNull($fresh->getCredential('missing'));
        $this->assertSame('fallback', $fresh->getCredential('missing', 'fallback'));
    }

    public function test_payments_method_enum_accepts_stripe_and_paypal(): void
    {
        $invoice = $this->createInvoice();

        $stripe = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'method' => 'stripe',
            'status' => 'completed',
        ]);

        $paypal = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 200.00,
            'method' => 'paypal',
            'status' => 'pending',
        ]);

        $this->assertSame('stripe', Payment::findOrFail($stripe->id)->method);
        $this->assertSame('paypal', Payment::findOrFail($paypal->id)->method);
        $this->assertDatabaseHas('payments', ['id' => $stripe->id, 'method' => 'stripe']);
        $this->assertDatabaseHas('payments', ['id' => $paypal->id, 'method' => 'paypal']);
    }

    private function createInvoice(): Invoice
    {
        $user = User::create([
            'email' => 'gateway-'.Str::random(8).'@example.com',
            'password_hash' => 'not-used-in-tests',
            'first_name' => 'Payment',
            'last_name' => 'Gateway',
            'status' => 'active',
        ]);

        $customer = Customer::create([
            'user_id' => $user->id,
            'company' => 'Gateway Test Co',
            'status' => 'active',
        ]);

        return Invoice::create([
            'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
            'customer_id' => $customer->id,
            'due_date' => now()->addDays(30)->toDateString(),
            'total' => 0.00,
            'status' => Invoice::STATUS_DRAFT,
        ]);
    }
}
