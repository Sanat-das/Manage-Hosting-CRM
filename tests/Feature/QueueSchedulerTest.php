<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Jobs\SyncDomainStatus;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SslCertificate;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Queue + scheduler runtime verification (Phases 4.2/4.3 close-out).
 *
 * billing:recurring drives the order-based renewal engine
 * (BillingService::processRecurringBilling) — orders with a due
 * next_billing_date get a sent invoice, then the cycle advances. The old
 * hosting-account-driven command and its GenerateInvoice job were removed in
 * the Order/IP refactor (the engine is BillingService now).
 */
class QueueSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Test Corp',
            'status' => 'active',
        ]);
    }

    // ---------------------------------------------------------------- jobs

    public function test_send_email_job_persists_log_and_sends(): void
    {
        Mail::fake();

        (new SendEmail('client@example.com', 'Invoice available', 'Your invoice is ready'))->handle();

        // status='sent' is only written AFTER Mail::raw() succeeds, and the
        // error-path test proves Mail::raw is genuinely invoked — so both the
        // send and the audit log are exercised here without a class assertion.
        $this->assertDatabaseHas('emails', [
            'to_email' => 'client@example.com',
            'subject' => 'Invoice available',
            'body' => 'Your invoice is ready',
            'status' => 'sent',
        ]);
    }

    public function test_send_email_job_marks_log_failed_on_mail_error(): void
    {
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('SMTP down'));

        try {
            (new SendEmail('client@example.com', 'Subject', 'Body'))->handle();
            $this->fail('Expected mail exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('SMTP down', $e->getMessage());
        }

        $this->assertDatabaseHas('emails', [
            'to_email' => 'client@example.com',
            'status' => 'failed',
        ]);

        $log = EmailLog::latest('id')->first();
        $this->assertSame('SMTP down', $log->error);
    }

    public function test_send_email_job_dispatches_on_emails_queue(): void
    {
        Queue::fake();

        SendEmail::dispatch('a@b.com', 's', 'b');

        Queue::assertPushedOn('emails', SendEmail::class);
    }

    public function test_sync_domain_status_marks_expired_domain(): void
    {
        $customer = $this->makeCustomer();
        $domain = Domain::create([
            'customer_id' => $customer->id,
            'name' => 'expired.example.com',
            'expiry_date' => now()->subDays(5)->toDateString(),
            'status' => 'active',
        ]);

        SyncDomainStatus::dispatchSync($domain->id);

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'status' => 'expired',
        ]);
    }

    public function test_sync_domain_status_leaves_active_domain_untouched(): void
    {
        $customer = $this->makeCustomer();
        $domain = Domain::create([
            'customer_id' => $customer->id,
            'name' => 'valid.example.com',
            'expiry_date' => now()->addDays(60)->toDateString(),
            'status' => 'active',
        ]);

        SyncDomainStatus::dispatchSync($domain->id);

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'status' => 'active',
        ]);
    }

    public function test_sync_domain_status_skips_missing_domain(): void
    {
        // Must not throw for a missing domain.
        SyncDomainStatus::dispatchSync(999_999);

        $this->assertTrue(true);
    }

    // ---------------------------------------------------------- commands

    private function makeActiveOrder(Customer $customer, Product $product, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => now()->subDay()->toDateString(),
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $order->quantity,
            'unit_price' => (float) $product->price,
            'total' => (float) $order->total,
        ]);

        return $order;
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting',
            'price' => 499.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ], $attributes));
    }

    public function test_billing_recurring_generates_invoice_for_due_order(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeActiveOrder($customer, $product);

        $before = Invoice::count();

        $this->artisan('billing:recurring')->assertExitCode(0);

        // One sent invoice for the due order, through the GST engine.
        $this->assertSame($before + 1, Invoice::count());
        $this->assertDatabaseHas('invoices', [
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'status' => 'sent',
            'amount' => 499.00,
        ]);

        // The cycle advanced by one month.
        $this->assertSame(
            now()->addMonth()->toDateString(),
            $order->fresh()->next_billing_date->toDateString()
        );
    }

    public function test_billing_recurring_skips_non_due_and_one_time_orders(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 100.00]);

        // Not due yet — must not be billed.
        $this->makeActiveOrder($customer, $product, [
            'next_billing_date' => now()->addDays(10)->toDateString(),
        ]);

        // one_time order with a null next_billing_date — must not be billed.
        $this->makeActiveOrder($customer, $product, [
            'billing_cycle' => 'one_time',
            'next_billing_date' => null,
        ]);

        $this->artisan('billing:recurring')->assertExitCode(0);

        $this->assertSame(0, Invoice::count());
    }

    public function test_billing_recurring_skips_zero_value_order(): void
    {
        $customer = $this->makeCustomer();
        $product = Product::create([
            'name' => 'Free Addon',
            'price' => 0,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);

        $this->makeActiveOrder($customer, $product);

        $this->artisan('billing:recurring')->assertExitCode(0);

        $this->assertSame(0, Invoice::count());
    }

    public function test_domains_expiry_check_marks_expired_domains(): void
    {
        $customer = $this->makeCustomer();

        Domain::create([
            'customer_id' => $customer->id,
            'name' => 'expired.example.com',
            'expiry_date' => now()->subDay()->toDateString(),
            'status' => 'active',
        ]);

        Domain::create([
            'customer_id' => $customer->id,
            'name' => 'ok.example.com',
            'expiry_date' => now()->addDays(60)->toDateString(),
            'status' => 'active',
        ]);

        $this->artisan('domains:expiry-check', ['--days' => 30])
            ->assertExitCode(0);

        $this->assertDatabaseHas('domains', ['name' => 'expired.example.com', 'status' => 'expired']);
        $this->assertDatabaseHas('domains', ['name' => 'ok.example.com', 'status' => 'active']);
    }

    public function test_hosting_usage_sync_runs(): void
    {
        $customer = $this->makeCustomer();
        $product = Product::create(['name' => 'Shared Hosting', 'price' => 100]);

        HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'username' => 'sync1',
            'domain' => 'sync.example.com',
            'status' => 'active',
        ]);

        $this->artisan('hosting:usage-sync')
            ->expectsOutputToContain('Syncing usage for 1 active hosting accounts.')
            ->assertExitCode(0);
    }

    public function test_app_cleanup_deletes_old_logs_only(): void
    {
        $old = now()->subDays(120);
        $recent = now()->subDays(10);

        // created_at is not mass-assignable on EmailLog, so seed directly via
        // the query builder to prove the cutoff filter works.
        DB::table('emails')->insert([
            ['to_email' => 'old@example.com', 'subject' => 'old', 'body' => 'x', 'status' => 'sent', 'created_at' => $old],
            ['to_email' => 'new@example.com', 'subject' => 'new', 'body' => 'x', 'status' => 'sent', 'created_at' => $recent],
        ]);

        $this->artisan('app:cleanup', ['--days' => 90])->assertExitCode(0);

        $this->assertDatabaseMissing('emails', ['to_email' => 'old@example.com']);
        $this->assertDatabaseHas('emails', ['to_email' => 'new@example.com']);
    }

    public function test_ssl_check_expiry_marks_expired_certificates(): void
    {
        $customer = $this->makeCustomer();

        SslCertificate::create([
            'customer_id' => $customer->id,
            'domain_name' => 'expired.example.com',
            'certificate_type' => 'single',
            'status' => 'active',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        SslCertificate::create([
            'customer_id' => $customer->id,
            'domain_name' => 'ok.example.com',
            'certificate_type' => 'single',
            'status' => 'active',
            'expiry_date' => now()->addDays(60)->toDateString(),
        ]);

        $this->artisan('ssl:check-expiry', ['--days' => 30])
            ->assertExitCode(0);

        $this->assertDatabaseHas('ssl_certificates', ['domain_name' => 'expired.example.com', 'status' => 'expired']);
        $this->assertDatabaseHas('ssl_certificates', ['domain_name' => 'ok.example.com', 'status' => 'active']);
    }

    public function test_ssl_check_expiry_reports_expiring_certificates(): void
    {
        $customer = $this->makeCustomer();

        SslCertificate::create([
            'customer_id' => $customer->id,
            'domain_name' => 'soon.example.com',
            'certificate_type' => 'wildcard',
            'status' => 'active',
            'expiry_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->artisan('ssl:check-expiry', ['--days' => 30])
            ->expectsOutputToContain('soon.example.com')
            ->assertExitCode(0);
    }

    public function test_reports_send_scheduled_dispatches_summary_email(): void
    {
        Queue::fake();

        $customer = $this->makeCustomer();
        Ticket::create(['customer_id' => $customer->id, 'ticket_no' => 'SUP-1', 'subject' => 'Open ticket', 'status' => 'open', 'priority' => 'medium']);

        $this->artisan('reports:send-scheduled', ['--days' => 7, '--to' => ['ops@example.com']])
            ->assertExitCode(0);

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) {
            return $job->toEmail === 'ops@example.com'
                && str_contains($job->subject, 'Business Summary');
        });
    }

    public function test_reports_send_scheduled_defaults_to_staff_recipients(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'email' => 'admin@example.com']);
        $admin->assignRole('admin');

        $this->artisan('reports:send-scheduled', ['--days' => 7])->assertExitCode(0);

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->toEmail === 'admin@example.com');
    }

    // ------------------------------------------------------ scheduler wiring

    public function test_all_scheduled_commands_are_registered(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('billing:recurring')
            ->expectsOutputToContain('domains:expiry-check')
            ->expectsOutputToContain('hosting:usage-sync')
            ->expectsOutputToContain('app:cleanup')
            ->expectsOutputToContain('ssl:check-expiry')
            ->expectsOutputToContain('reports:send-scheduled')
            ->assertExitCode(0);
    }
}
