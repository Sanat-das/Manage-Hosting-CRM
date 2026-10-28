<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\RenewalReminderService;
use App\Services\Billing\UpgradeRequestService;
use App\Services\InvoiceEmailService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Durability of a recurring-billing run: the invoice and the schedule advance
 * are one unit of money-truth, while the two post-commit side effects
 * (superseded upgrade cancellation, renewal invoice email) must not roll the
 * invoice back or cost the run its schedule advance. A failed upgrade cancel is
 * internal bookkeeping and not an error; a renewal invoice the client never
 * receives is a failed deliverable and IS counted as one.
 */
class RenewalBillingResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie settings are container-scoped; resolve a fresh instance.
        app()->forgetScopedInstances();

        // Production always carries the renewal template: without it the send
        // path returns false and every run would (correctly) report a
        // non-delivery, which is not what these two tests are about.
        EmailTemplate::create([
            'name' => 'invoice_created',
            'subject' => 'Your invoice {{invoice_no}}',
            'body' => 'Invoice {{invoice_no}} is ready.',
            'status' => 'active',
        ]);
    }

    /**
     * An invoice email that throws after the commit must leave the invoice
     * committed and the schedule advanced, so the next run does not re-bill the
     * same period — while the non-delivery itself is a failed deliverable and
     * must be counted as a billing error for the run.
     */
    public function test_an_invoice_email_failure_does_not_undo_the_renewal(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $order = $this->makeDueOrder($asOf);
        $item = $order->items()->sole();

        $emailer = new class
        {
            public int $attempts = 0;

            public function send(Invoice $invoice, string $templateName = 'invoice_created'): bool
            {
                $this->attempts++;

                throw new RuntimeException('simulated invoice mail transport failure');
            }
        };

        // InvoiceEmailService is final, so a plain mock cannot replace it: the
        // container binding is the seam BillingService resolves through.
        $this->instance(InvoiceEmailService::class, $emailer);

        $service = app(BillingService::class);
        $first = $service->processRecurringBilling($asOf);

        $this->assertSame(1, $emailer->attempts, 'The failing email path must actually be exercised.');
        $this->assertSame(1, $first['invoices_generated']);
        $this->assertSame(1, $first['errors'], 'A renewal invoice the client never received is a failed deliverable and must count as a billing error.');

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);

        // The schedule advanced with the commit: no re-bill of the same period.
        $this->assertSame($asOf->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $item->fresh()->billing_cycles_count);

        $second = $service->processRecurringBilling($asOf);

        $this->assertSame(0, $second['invoices_generated']);
        $this->assertSame(0, $second['errors']);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
    }

    /**
     * A send that reports the email was skipped (false, not a throw) is the
     * same failed deliverable: the invoice stays committed, the run counts it
     * in errors, and the schedule stays advanced.
     */
    public function test_an_undispatched_invoice_email_counts_as_an_error(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $order = $this->makeDueOrder($asOf);
        $item = $order->items()->sole();

        $emailer = new class
        {
            public int $attempts = 0;

            public function send(Invoice $invoice, string $templateName = 'invoice_created'): bool
            {
                $this->attempts++;

                return false;
            }
        };

        $this->instance(InvoiceEmailService::class, $emailer);

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $emailer->attempts, 'The undispatched email path must actually be exercised.');
        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(1, $result['errors'], 'A renewal invoice the client never received is a failed deliverable and must count as a billing error.');

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertSame($asOf->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $item->fresh()->billing_cycles_count);
    }

    /**
     * The invoice email is the deliverable, and the template it renders is a
     * production configuration (active `invoice_created` row). With no such
     * template the send path reports a non-delivery by design, so the run must
     * count an error — while the invoice and its schedule advance, which are
     * money-truth, stay committed.
     */
    public function test_a_missing_invoice_template_counts_as_an_error(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $order = $this->makeDueOrder($asOf);
        $item = $order->items()->sole();

        // No template at all, not merely an inactive one: that is the shape of
        // the fault on an install whose mail templates were never seeded.
        EmailTemplate::query()->where('name', 'invoice_created')->delete();

        $result = app(BillingService::class)->processRecurringBilling($asOf);

        $this->assertSame(1, $result['invoices_generated']);
        $this->assertSame(1, $result['errors'], 'A renewal invoice the client never received is a failed deliverable and must count as a billing error.');
        $this->assertDatabaseCount('emails', 0);

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);

        // The schedule advanced with the commit: no re-bill of the same period.
        $this->assertSame($asOf->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $item->fresh()->billing_cycles_count);
    }

    /**
     * A missing reminder template is a configuration fault, not a transient
     * one: the client email cannot be dispatched (false, so the run goes red),
     * but the portal notification still goes out and the per-cycle marker is
     * still written, so fixing the template does not unleash a backlog of
     * reminders for cycles that were already reported.
     */
    public function test_a_missing_reminder_template_returns_false(): void
    {
        $order = $this->makeDueOrder(CarbonImmutable::today('Asia/Kolkata'));
        $due = $order->next_billing_date->toDateString();

        // The migration backfills an active `renewal_reminder` row, so model
        // the fault the way an inactive/deleted template presents: nothing the
        // service can find by name and status.
        EmailTemplate::query()
            ->where('name', 'renewal_reminder')
            ->update(['status' => 'inactive']);

        $this->assertFalse(app(RenewalReminderService::class)->remind($order));
        $this->assertDatabaseCount('emails', 0);

        $user = $order->customer->user;
        $this->assertSame(1, $user->fresh()->notifications()->count(), 'The in-app channel is unaffected by the missing template.');
        $this->assertSame('renewal.upcoming', $user->fresh()->notifications()->first()->data['type']);

        $reminded = $order->fresh();
        $this->assertSame($due, $reminded->renewal_reminder_for->toDateString(), 'The cycle marker is stamped even when the email could not be rendered.');
        $this->assertNotNull($reminded->renewal_reminder_sent_at);
    }

    /**
     * The superseded-upgrade cancellation is also post-commit bookkeeping: a
     * throw there must not roll the invoice back or stop the run.
     */
    public function test_a_superseded_upgrade_cancel_failure_does_not_undo_the_renewal(): void
    {
        $asOf = CarbonImmutable::today('Asia/Kolkata');
        $order = $this->makeDueOrder($asOf);
        $item = $order->items()->sole();

        $this->mock(UpgradeRequestService::class)
            ->shouldReceive('cancelUnpaidForOrder')
            ->once()
            ->andThrow(new RuntimeException('simulated upgrade cancel failure'));

        $service = app(BillingService::class);
        $first = $service->processRecurringBilling($asOf);

        $this->assertSame(1, $first['invoices_generated']);
        $this->assertSame(0, $first['errors'], 'A failed upgrade cancel must not count as a billing error.');

        $invoice = Invoice::where('order_id', $order->id)->sole();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);

        $this->assertSame($asOf->addMonth()->toDateString(), $item->fresh()->next_billing_date->toDateString());
        $this->assertSame(2, $item->fresh()->billing_cycles_count);

        $second = $service->processRecurringBilling($asOf);

        $this->assertSame(0, $second['invoices_generated']);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
    }

    /**
     * A monthly order due on the run date, with the default (zero-day)
     * generation window: it must be invoiced by this run.
     */
    private function makeDueOrder(CarbonImmutable $asOf): Order
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 100.00]);

        // Due today with the default zero-day window: billed on this run.
        $due = $asOf->toDateString();

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => $due,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => (float) $product->price,
            'total' => (float) $product->price,
            'next_billing_date' => $due,
            'billing_cycles_count' => 1,
        ]);

        return $order;
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Renewal Resilience Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ], $attributes));
    }
}
