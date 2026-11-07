<?php

namespace Tests\Feature;

use App\Events\InvoicePaid;
use App\Jobs\SendEmail;
use App\Listeners\SendPaymentReceivedNotification;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Notifications\NotificationMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * InvoicePaid → SendPaymentReceivedNotification → InvoiceEmailService::send()
 * with the 'payment_received' template. The matrix gate (invoice.paid /
 * customer / mail) lives inside send(); the listener only checks that a
 * linked Customer exists.
 */
class PaymentReceivedEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_paid_dispatches_payment_received_email(): void
    {
        Queue::fake();
        Storage::fake('local');
        NotificationMatrix::flush();

        $this->makePaymentReceivedTemplate();
        $invoice = $this->makePaidInvoice($this->makeCustomer());

        InvoicePaid::dispatch($invoice);

        Queue::assertPushedTimes(SendEmail::class, 1);
        Queue::assertPushed(
            SendEmail::class,
            fn (SendEmail $job) => $job->templateName === 'payment_received'
        );
    }

    public function test_matrix_disabled_invoice_paid_mail_suppresses_the_email(): void
    {
        Queue::fake();
        Storage::fake('local');

        DB::table('notification_event_settings')->updateOrInsert(
            ['event_key' => 'invoice.paid', 'audience' => 'customer', 'channel' => 'mail'],
            ['enabled' => false, 'updated_at' => now()],
        );
        NotificationMatrix::flush();

        $this->makePaymentReceivedTemplate();
        $invoice = $this->makePaidInvoice($this->makeCustomer());

        InvoicePaid::dispatch($invoice);

        Queue::assertNotPushed(SendEmail::class);

        NotificationMatrix::flush();
    }

    public function test_invoice_paid_without_linked_customer_sends_nothing(): void
    {
        Queue::fake();
        Storage::fake('local');

        // The sibling listeners assume the invoice row still exists and would
        // fatal on the cascade delete below; re-register only the listener
        // under test (the application rebuilds its event map every test).
        Event::forget(InvoicePaid::class);
        Event::listen(InvoicePaid::class, SendPaymentReceivedNotification::class);

        $this->makePaymentReceivedTemplate();
        $customer = $this->makeCustomer();
        $invoice = $this->makePaidInvoice($customer);

        // Deleting the customer cascades to the invoice; fresh() then
        // resolves null and the listener must bail out quietly.
        $customer->delete();

        InvoicePaid::dispatch($invoice);

        Queue::assertNotPushed(SendEmail::class);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);
    }

    private function makePaidInvoice(Customer $customer): Invoice
    {
        return Invoice::create([
            'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => Invoice::STATUS_PAID,
            'due_date' => now()->addDays(7)->toDateString(),
            'paid_at' => now(),
        ]);
    }

    private function makePaymentReceivedTemplate(): EmailTemplate
    {
        return EmailTemplate::create([
            'name' => 'payment_received',
            'subject' => 'Payment received for invoice {{invoice_no}}',
            'body' => 'Hi {{customer_name}}, we received your payment for invoice {{invoice_no}}.',
            'status' => 'active',
        ]);
    }
}
