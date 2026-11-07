<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoiceOverdueNotification;
use App\Support\Notifications\NotificationMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * invoices:overdue-check flips past-due unpaid invoices to overdue and
 * dispatches the InvoiceOverdue event for the invoices transitioned in that
 * run only; the listener gates the in-app InvoiceOverdueNotification through
 * NotificationPreferenceService (matrix: invoice.overdue / customer /
 * database).
 */
class OverdueInvoiceNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);
    }

    private function makePastDueInvoice(Customer $customer): Invoice
    {
        return Invoice::create([
            'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->subDays(3)->toDateString(),
        ]);
    }

    // ── (1) flip + dispatch → customer receives the database notification ──

    public function test_overdue_check_flips_past_due_invoice_and_notifies_customer(): void
    {
        Queue::fake();
        Notification::fake();
        NotificationMatrix::flush();

        $customer = $this->makeCustomer();
        $invoice = $this->makePastDueInvoice($customer);

        $this->artisan('invoices:overdue-check')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_OVERDUE, $invoice->fresh()->status);

        Notification::assertSentTo($customer, InvoiceOverdueNotification::class);
    }

    // ── (2) matrix disabled for invoice.overdue/customer/database → silent ──

    public function test_overdue_check_respects_matrix_disabled_invoice_overdue_database(): void
    {
        Queue::fake();
        Notification::fake();

        DB::table('notification_event_settings')
            ->where('event_key', 'invoice.overdue')
            ->where('audience', 'customer')
            ->where('channel', 'database')
            ->update(['enabled' => false, 'updated_at' => now()]);

        NotificationMatrix::flush();

        $customer = $this->makeCustomer();
        $invoice = $this->makePastDueInvoice($customer);

        $this->artisan('invoices:overdue-check')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_OVERDUE, $invoice->fresh()->status);
        Notification::assertNotSentTo($customer, InvoiceOverdueNotification::class);

        NotificationMatrix::flush();
    }

    // ── (3) already-overdue invoice is not re-notified on a second run ──

    public function test_overdue_check_does_not_redispatch_for_already_overdue_invoice(): void
    {
        Queue::fake();
        Notification::fake();
        NotificationMatrix::flush();

        $customer = $this->makeCustomer();
        $invoice = $this->makePastDueInvoice($customer);

        $this->artisan('invoices:overdue-check')->assertExitCode(0);
        $this->artisan('invoices:overdue-check')->assertExitCode(0);

        Notification::assertSentToTimes($customer, InvoiceOverdueNotification::class, 1);
    }
}
