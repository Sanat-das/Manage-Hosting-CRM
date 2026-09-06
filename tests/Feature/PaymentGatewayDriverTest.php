<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PaymentGatewayDriver;
use App\Models\PaymentGateway;
use App\Services\Payments\Drivers\BankTransferDriver;
use App\Services\Payments\Drivers\ManualDriver;
use App\Services\Payments\Drivers\PaypalDriver;
use App\Services\Payments\Drivers\RazorpayDriver;
use App\Services\Payments\Drivers\StripeDriver;
use App\Services\Payments\PaymentGatewayManager;
use Database\Seeders\PaymentGatewaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PaymentGatewayDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_resolves_driver_from_gateway(): void
    {
        $manager = app(PaymentGatewayManager::class);

        $definitions = [
            ['code' => 'stripe', 'driver' => StripeDriver::class],
            ['code' => 'paypal', 'driver' => PaypalDriver::class],
            ['code' => 'razorpay', 'driver' => RazorpayDriver::class],
            ['code' => 'bank_transfer', 'driver' => BankTransferDriver::class],
            ['code' => 'manual', 'driver' => ManualDriver::class],
        ];

        foreach ($definitions as $definition) {
            $gateway = $this->gateway($definition['code'], $definition['driver']);

            $this->assertInstanceOf(PaymentGatewayDriver::class, $manager->driverFor($gateway));
            $this->assertInstanceOf($definition['driver'], $manager->driverFor($gateway));
        }

        $this->assertContains('stripe', $manager->supportedCodes());
        $this->assertContains('paypal', $manager->supportedCodes());
        $this->assertContains('razorpay', $manager->supportedCodes());
        $this->assertContains('bank_transfer', $manager->supportedCodes());
        $this->assertContains('manual', $manager->supportedCodes());
    }

    public function test_stripe_driver_creates_payment_intent(): void
    {
        $gateway = $this->gateway('stripe', StripeDriver::class, ['secret_key' => 'sk_test_123']);

        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 'pi_test_1', 'status' => 'requires_confirmation', 'client_secret' => 'pi_test_1_secret'], 200),
        ]);

        $result = app(PaymentGatewayManager::class)->driverFor($gateway)->purchase($this->payload('stripe', 100.00, $gateway));

        $this->assertSame('redirect', $result['status']);
        $this->assertSame('pi_test_1', $result['reference']);
        $this->assertNotNull($result['redirect_url']);
    }

    public function test_stripe_driver_verify_confirmed(): void
    {
        $gateway = $this->gateway('stripe', StripeDriver::class, ['secret_key' => 'sk_test_123']);

        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 'pi_test_1', 'status' => 'succeeded', 'amount' => 10000], 200),
        ]);

        $result = app(PaymentGatewayManager::class)->driverFor($gateway)->verify(['reference' => 'pi_test_1', 'gateway' => $gateway]);

        $this->assertTrue($result['verified']);
        $this->assertSame('pi_test_1', $result['gateway_transaction_id']);
        $this->assertSame(100.0, $result['amount']);
    }

    public function test_stripe_unconfigured_throws(): void
    {
        $gateway = $this->gateway('stripe', StripeDriver::class);

        $driver = app(PaymentGatewayManager::class)->driverFor($gateway);

        $this->expectException(RuntimeException::class);

        $driver->purchase($this->payload('stripe', 100.00, $gateway));
    }

    public function test_paypal_driver_creates_order(): void
    {
        $gateway = $this->gateway('paypal', PaypalDriver::class, ['client_id' => 'paypal-client-id', 'client_secret' => 'paypal-client-secret']);

        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'sandbox-token'], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL-ORDER-1',
                'status' => 'CREATED',
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-ORDER-1'],
                    ['rel' => 'approve', 'href' => 'https://www.paypal.com/checkoutnow?token=PAYPAL-ORDER-1'],
                ],
            ], 200),
        ]);

        $result = app(PaymentGatewayManager::class)->driverFor($gateway)->purchase($this->payload('paypal', 50.00, $gateway, 'USD'));

        $this->assertSame('redirect', $result['status']);
        $this->assertSame('PAYPAL-ORDER-1', $result['reference']);
        $this->assertNotNull($result['redirect_url']);
        $this->assertStringContainsString('paypal.com', $result['redirect_url']);
    }

    public function test_paypal_driver_verify_completed(): void
    {
        $gateway = $this->gateway('paypal', PaypalDriver::class, ['client_id' => 'paypal-client-id', 'client_secret' => 'paypal-client-secret']);

        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'sandbox-token'], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders/*' => Http::response([
                'id' => 'PAYPAL-ORDER-1',
                'status' => 'COMPLETED',
                'purchase_units' => [
                    ['payments' => ['captures' => [['amount' => ['value' => '50.00']]]]],
                ],
            ], 200),
        ]);

        $result = app(PaymentGatewayManager::class)->driverFor($gateway)->verify(['reference' => 'PAYPAL-ORDER-1', 'gateway' => $gateway]);

        $this->assertTrue($result['verified']);
        $this->assertSame('PAYPAL-ORDER-1', $result['gateway_transaction_id']);
    }

    public function test_razorpay_driver_creates_order(): void
    {
        $gateway = $this->gateway('razorpay', RazorpayDriver::class, ['key_id' => 'rzp_test_key', 'key_secret' => 'rzp_test_secret']);

        Http::fake([
            'api.razorpay.com/*' => Http::response(['id' => 'order_test_1', 'status' => 'created', 'short_url' => 'https://rzp.io/l/test1'], 200),
        ]);

        $driver = app(PaymentGatewayManager::class)->driverFor($gateway);
        $result = $driver->purchase($this->payload('razorpay', 123.45, $gateway));

        $this->assertSame('redirect', $result['status']);
        $this->assertSame('order_test_1', $result['reference']);
        $this->assertNotNull($result['redirect_url']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->body(), 'amount=12345');
        });
    }

    public function test_razorpay_driver_verify_paid(): void
    {
        $gateway = $this->gateway('razorpay', RazorpayDriver::class, ['key_id' => 'rzp_test_key', 'key_secret' => 'rzp_test_secret']);

        Http::fake([
            'api.razorpay.com/*' => Http::response(['id' => 'order_test_1', 'status' => 'paid'], 200),
        ]);

        $result = app(PaymentGatewayManager::class)->driverFor($gateway)->verify(['reference' => 'order_test_1', 'gateway' => $gateway]);

        $this->assertTrue($result['verified']);
        $this->assertSame('order_test_1', $result['gateway_transaction_id']);
    }

    public function test_bank_transfer_returns_manual_instructions(): void
    {
        $gateway = $this->gateway('bank_transfer', BankTransferDriver::class, [
            'account_name' => 'ManageHosting Ltd',
            'account_number' => '1234567890',
            'bank_name' => 'HDFC Bank',
            'ifsc' => 'HDFC0000123',
            'instructions' => 'Use your invoice number as the payment reference.',
        ]);

        $result = app(PaymentGatewayManager::class)->driverFor($gateway)->purchase($this->payload('bank', 250.00, $gateway));

        $this->assertSame('manual', $result['status']);
        $this->assertSame('REF-BANK', $result['reference']);
        $this->assertNotEmpty($result['instructions']);
        $this->assertSame('Complete the bank transfer using the instructions below.', $result['message']);
    }

    public function test_manual_driver_returns_manual(): void
    {
        $gateway = $this->gateway('manual', ManualDriver::class);
        $driver = app(PaymentGatewayManager::class)->driverFor($gateway);

        $result = $driver->purchase($this->payload('manual', 50.00, $gateway));

        $this->assertSame('manual', $result['status']);
        $this->assertSame('REF-MANUAL', $result['reference']);
        $this->assertSame('Payment recorded manually by an administrator.', $result['message']);

        $verify = $driver->verify(['reference' => 'REF-MANUAL', 'gateway' => $gateway]);

        $this->assertFalse($verify['verified']);
    }

    public function test_seeded_gateways_resolve_drivers(): void
    {
        Artisan::call('db:seed', ['--class' => PaymentGatewaySeeder::class]);

        $manager = app(PaymentGatewayManager::class);

        $this->assertCount(5, $manager->all());
        $this->assertSame(['bank_transfer', 'manual', 'stripe', 'paypal', 'razorpay'], $manager->supportedCodes());

        foreach ($manager->all() as $gateway) {
            $this->assertInstanceOf(PaymentGatewayDriver::class, $manager->driverFor($gateway));
        }
    }

    private function gateway(string $code, string $driver, array $credentials = []): PaymentGateway
    {
        return PaymentGateway::create([
            'code' => $code,
            'name' => ucfirst($code),
            'driver' => $driver,
            'mode' => 'test',
            'enabled' => true,
            'credentials' => $credentials,
        ]);
    }

    private function payload(string $ref, float $amount, PaymentGateway $gateway, string $currency = 'INR'): array
    {
        return [
            'purchase_ref' => 'REF-'.strtoupper($ref),
            'amount' => $amount,
            'currency' => $currency,
            'description' => 'Payment for purchase REF-'.strtoupper($ref),
            'gateway' => $gateway,
        ];
    }
}
