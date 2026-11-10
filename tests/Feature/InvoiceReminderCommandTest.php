<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\User;
use App\Settings\AutomationSettings;
use App\Support\Notifications\NotificationMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Past-due invoices that are still unpaid are chased on a ladder.
 *
 * The flip to 'overdue' sends one reminder; this command repeats it every
 * `automation_invoice_reminder_days` until the invoice is paid, and records
 * the reminder on the invoice (reminder_count / last_reminder_at) so the
 * ladder knows where it stands.
 */
class InvoiceReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie settings are container-scoped; resolve a fresh instance.
        app()->forgetScopedInstances();

        NotificationMatrix::flush();

        EmailTemplate::firstOrCreate(
            ['name' => 'invoice_overdue_reminder'],
            [
                'subject' => 'Invoice {{invoice_no}} is overdue',
                'body' => 'Invoice {{invoice_no}} for {{total}} is past due.',
                'status' => 'active',
            ],
        );
    }

    public function test_a_past_due_unpaid_invoice_is_reminded_and_the_reminder_is_recorded(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makePastDueInvoice($customer);

        $this->artisan('invoices:remind')->assertExitCode(0);

        $this->assertDatabaseHas('emails', [
            'to_email' => $customer->user->email,
            'status' => 'sent',
        ]);

        $invoice->refresh();

        $this->assertSame(1, (int) $invoice->reminder_count);
        $this->assertNotNull($invoice->last_reminder_at);
    }

    public function test_the_ladder_does_not_remind_twice_on_the_same_day(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makePastDueInvoice($customer);

        $this->artisan('invoices:remind')->assertExitCode(0);
        $this->artisan('invoices:remind')->assertExitCode(0);

        $invoice->refresh();

        $this->assertSame(1, (int) $invoice->reminder_count);
    }

    public function test_the_ladder_reminds_again_once_the_cadence_has_passed(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makePastDueInvoice($customer);

        $this->artisan('invoices:remind')->assertExitCode(0);

        // The cadence is 3 days by default.
        $invoice->update(['last_reminder_at' => now()->subDays(4)]);

        $this->artisan('invoices:remind')->assertExitCode(0);

        $invoice->refresh();

        $this->assertSame(2, (int) $invoice->reminder_count);
    }

    public function test_a_paid_invoice_is_never_reminded(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makePastDueInvoice($customer, ['paid_amount' => 100.0, 'status' => Invoice::STATUS_PAID]);

        $this->artisan('invoices:remind')->assertExitCode(0);

        $invoice->refresh();

        $this->assertSame(0, (int) $invoice->reminder_count);
        $this->assertDatabaseMissing('emails', ['to_email' => $customer->user->email]);
    }

    public function test_the_master_switch_stops_the_ladder(): void
    {
        $settings = app(AutomationSettings::class);
        $settings->automation_invoice_reminders = false;
        $settings->save();

        app()->forgetScopedInstances();

        $customer = $this->makeCustomer();
        $invoice = $this->makePastDueInvoice($customer);

        $this->artisan('invoices:remind')->assertExitCode(0);

        $invoice->refresh();

        $this->assertSame(0, (int) $invoice->reminder_count);
        $this->assertDatabaseMissing('emails', ['to_email' => $customer->user->email]);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Reminder Corp',
            'status' => 'active',
        ]);
    }

    private function makePastDueInvoice(Customer $customer, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_no' => 'INV-2026-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->subDays(3)->toDateString(),
        ], $overrides));
    }
}
