<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use App\Services\Payments\Drivers\BankTransferDriver;
use App\Services\Payments\Drivers\ManualDriver;
use App\Services\Payments\Drivers\PaypalDriver;
use App\Services\Payments\Drivers\RazorpayDriver;
use App\Services\Payments\Drivers\StripeDriver;
use Illuminate\Database\Seeder;

/**
 * Seeds the payment gateway registry. Idempotent via updateOrCreate on `code`.
 * Online gateways (stripe/paypal/razorpay) are seeded disabled because they
 * need credentials to be configured before they can go live.
 */
class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $gateways = [
            ['code' => 'bank_transfer', 'name' => 'Bank Transfer', 'driver' => BankTransferDriver::class, 'enabled' => true, 'sort_order' => 10, 'mode' => 'test'],
            ['code' => 'manual', 'name' => 'Manual / Cash', 'driver' => ManualDriver::class, 'enabled' => true, 'sort_order' => 20, 'mode' => 'test'],
            ['code' => 'stripe', 'name' => 'Stripe', 'driver' => StripeDriver::class, 'enabled' => false, 'sort_order' => 30, 'mode' => 'test'],
            ['code' => 'paypal', 'name' => 'PayPal', 'driver' => PaypalDriver::class, 'enabled' => false, 'sort_order' => 40, 'mode' => 'test'],
            ['code' => 'razorpay', 'name' => 'Razorpay', 'driver' => RazorpayDriver::class, 'enabled' => false, 'sort_order' => 50, 'mode' => 'test'],
        ];

        foreach ($gateways as $gateway) {
            PaymentGateway::updateOrCreate(['code' => $gateway['code']], $gateway);
        }
    }
}
