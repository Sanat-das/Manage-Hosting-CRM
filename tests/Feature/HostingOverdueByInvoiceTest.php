<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\HostingService;
use App\Settings\HostingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An unpaid invoice makes the service overdue — and can suspend it.
 *
 * The renewal engine advances the service's billing schedule when it issues
 * the invoice, so a customer who never paid left a service that looked due
 * next year: the overdue check read the schedule, saw nothing, and the
 * money was never chased.
 */
class HostingOverdueByInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie settings are container-scoped; resolve a fresh instance.
        app()->forgetScopedInstances();

        EmailTemplate::firstOrCreate(
            ['name' => 'service_suspended'],
            [
                'subject' => 'Service suspended',
                'body' => 'Your service {{service}} was suspended: {{reason}}',
                'status' => 'active',
            ],
        );
    }

    public function test_an_unpaid_invoice_makes_the_service_overdue_even_when_the_schedule_is_a_year_away(): void
    {
        [$account, $invoice] = $this->makeServiceWithUnpaidInvoice(daysPastDue: 3);

        $this->artisan('hosting:overdue-check')
            ->expectsOutputToContain('1 overdue')
            ->assertExitCode(0);

        // The switch is off by default: overdue is reported, nothing is
        // suspended.
        $this->assertSame(HostingService::STATUS_ACTIVE, $account->fresh()->status);
    }

    public function test_the_service_is_suspended_after_the_configured_grace_period(): void
    {
        $this->enableSuspension();

        [$account, $invoice] = $this->makeServiceWithUnpaidInvoice(daysPastDue: 10);

        $this->artisan('hosting:overdue-check')->assertExitCode(0);

        $account->refresh();

        $this->assertSame(HostingService::STATUS_SUSPENDED, $account->status);
        $this->assertStringContainsString($invoice->invoice_no, (string) $account->suspended_reason);
        $this->assertNotNull($account->suspended_at);

        // The customer is told.
        $this->assertDatabaseHas('emails', [
            'to_email' => $account->customer->user->email,
            'status' => 'sent',
        ]);
    }

    public function test_a_service_within_the_grace_period_is_not_suspended(): void
    {
        $this->enableSuspension();

        [$account] = $this->makeServiceWithUnpaidInvoice(daysPastDue: 3);

        $this->artisan('hosting:overdue-check')->assertExitCode(0);

        $this->assertSame(HostingService::STATUS_ACTIVE, $account->fresh()->status);
    }

    public function test_a_paid_invoice_does_not_make_the_service_overdue(): void
    {
        [$account, $invoice] = $this->makeServiceWithUnpaidInvoice(daysPastDue: 10);

        $invoice->update(['status' => Invoice::STATUS_PAID, 'paid_amount' => 100.0, 'paid_at' => now()]);

        $this->artisan('hosting:overdue-check')
            ->expectsOutputToContain('none')
            ->assertExitCode(0);

        $this->assertSame(HostingService::STATUS_ACTIVE, $account->fresh()->status);
    }

    private function enableSuspension(): void
    {
        $settings = app(HostingSettings::class);
        $settings->hosting_suspend_on_overdue = true;
        $settings->save();

        app()->forgetScopedInstances();
    }

    /**
     * A live service whose order is due next year, with an invoice the
     * customer has not paid.
     *
     * @return array{0: HostingAccount, 1: Invoice}
     */
    private function makeServiceWithUnpaidInvoice(int $daysPastDue): array
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        $customer = Customer::create([
            'user_id' => $user->id,
            'company' => 'Overdue Corp',
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Shared Hosting',
            'price' => 100.00,
            'billing_cycle' => 'annual',
            'status' => 'active',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_ACTIVE,
            // The schedule already sits a year ahead — the renewal engine
            // moved it when it issued the invoice.
            'next_billing_date' => now()->addYear()->toDateString(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'annual',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'next_billing_date' => now()->addYear()->toDateString(),
            'last_billing_date' => now()->toDateString(),
            'billing_cycles_count' => 1,
        ]);

        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'domain' => 'overdue.example',
            'status' => HostingService::STATUS_ACTIVE,
        ]);

        $invoice = Invoice::create([
            'invoice_no' => 'INV-2026-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->subDays($daysPastDue)->toDateString(),
        ]);

        return [$account, $invoice];
    }
}
