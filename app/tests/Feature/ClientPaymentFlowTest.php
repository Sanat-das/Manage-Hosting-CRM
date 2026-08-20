<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\Payments\Drivers\BankTransferDriver;
use App\Services\Payments\Drivers\StripeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_gateway_purchase_creates_pending_payment(): void
    {
        $user = $this->clientUser();
        $invoice = $this->invoiceFor($user, 100.00, Invoice::STATUS_SENT);

        $this->gateway('bank_transfer', BankTransferDriver::class, [
            'account_name' => 'ManageHosting Ltd',
            'account_number' => '1234567890',
            'bank_name' => 'HDFC Bank',
            'ifsc' => 'HDFC0000123',
            'instructions' => 'Use your invoice number as the payment reference.',
        ]);

        $response = $this->actingAs($user)->post("/client/invoices/{$invoice->id}/pay", [
            'gateway' => 'bank_transfer',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'method' => 'bank_transfer',
            'status' => 'pending',
        ]);
    }

    public function test_paid_invoice_redirects_back(): void
    {
        $user = $this->clientUser();
        $invoice = $this->invoiceFor($user, 100.00, Invoice::STATUS_PAID);

        $response = $this->actingAs($user)->get("/client/invoices/{$invoice->id}/pay");

        $response->assertRedirect(route('client.invoices.show', $invoice));
    }

    public function test_return_callback_marks_payment_completed(): void
    {
        $user = $this->clientUser();
        $invoice = $this->invoiceFor($user, 100.00, Invoice::STATUS_SENT);
        $stripe = $this->gateway('stripe', StripeDriver::class, ['secret_key' => 'sk_test_123']);

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'method' => 'stripe',
            'gateway_id' => (string) $stripe->id,
            'transaction_id' => 'pi_test_1',
            'status' => 'pending',
        ]);

        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 'pi_test_1', 'status' => 'succeeded', 'amount' => 10000], 200),
        ]);

        $response = $this->actingAs($user)->get("/client/invoices/{$invoice->id}/pay/return");

        $response->assertRedirect(route('client.invoices.show', $invoice));

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'method' => 'stripe',
            'status' => 'completed',
            'transaction_id' => 'pi_test_1',
        ]);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEqualsWithDelta(100.00, (float) $invoice->fresh()->paid_amount, 0.001);
    }

    public function test_client_cannot_pay_another_customers_invoice(): void
    {
        $user = $this->clientUser();
        $this->customerFor($user);

        $otherUser = $this->clientUser();
        $invoice = $this->invoiceFor($otherUser, 100.00, Invoice::STATUS_SENT);

        $this->actingAs($user)
            ->get("/client/invoices/{$invoice->id}/pay")
            ->assertForbidden();
    }

    private function clientUser(): User
    {
        return User::factory()->create();
    }

    private function customerFor(User $user): Customer
    {
        return Customer::create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    private function invoiceFor(User $user, float $total, string $status): Invoice
    {
        $customer = $this->customerFor($user);

        return Invoice::create([
            'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
            'customer_id' => $customer->id,
            'amount' => $total,
            'tax' => 0,
            'total' => $total,
            'status' => $status,
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    private function gateway(string $code, string $driver, array $credentials = []): PaymentGateway
    {
        return PaymentGateway::create([
            'code' => $code,
            'name' => ucfirst(str_replace('_', ' ', $code)),
            'driver' => $driver,
            'mode' => 'test',
            'enabled' => true,
            'credentials' => $credentials,
        ]);
    }
}
