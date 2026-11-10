<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The overdue check only touches invoices the customer has actually seen.
 *
 * It used to flip DRAFT invoices to 'overdue' as well — a draft was never
 * sent, so its due date means nothing to the customer — and it flipped a
 * partly paid invoice to a state its own transition map did not allow.
 */
class OverdueInvoiceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_invoice_is_not_flipped_to_overdue(): void
    {
        $draft = $this->makeInvoice(Invoice::STATUS_DRAFT, daysOverdue: 5);

        $this->artisan('invoices:overdue-check')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_a_sent_invoice_is_flipped_to_overdue(): void
    {
        $sent = $this->makeInvoice(Invoice::STATUS_SENT, daysOverdue: 5);

        $this->artisan('invoices:overdue-check')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_OVERDUE, $sent->fresh()->status);
    }

    public function test_a_partly_paid_invoice_is_flipped_to_overdue(): void
    {
        $partial = $this->makeInvoice(Invoice::STATUS_PARTIAL, daysOverdue: 5, paidAmount: 50.0);

        $this->artisan('invoices:overdue-check')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_OVERDUE, $partial->fresh()->status);
    }

    public function test_a_partly_paid_invoice_may_be_moved_to_overdue_from_the_edit_form(): void
    {
        $partial = $this->makeInvoice(Invoice::STATUS_PARTIAL, daysOverdue: 5, paidAmount: 50.0);

        // The status a form may offer must be a status the transition map
        // allows: the cron and the form have to agree.
        $this->assertContains(
            Invoice::STATUS_OVERDUE,
            array_keys($partial->editableStatusTransitions())
        );
    }

    private function makeInvoice(string $status, int $daysOverdue, float $paidAmount = 0.0): Invoice
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        return Invoice::create([
            'invoice_no' => 'INV-2026-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'paid_amount' => $paidAmount,
            'status' => $status,
            'due_date' => now()->subDays($daysOverdue)->toDateString(),
        ]);
    }
}
