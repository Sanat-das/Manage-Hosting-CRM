<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Invoice emails (invoice_created / invoice_overdue_reminder / payment_received)
 * must carry a downloadable PDF of the invoice.
 *
 * The file is written to the local disk at a deterministic path so a re-send
 * overwrites the same file — no cleanup is expected from the service; tests
 * delete what they create to leave no residue.
 */
class InvoiceEmailAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_created_email_carries_a_downloadable_pdf_attachment(): void
    {
        $this->queueAndStorage();

        EmailTemplate::create([
            'name' => 'invoice_created',
            'subject' => 'Invoice {{invoice_no}}',
            'body' => 'Hi {{customer_name}}, your invoice is ready.',
            'status' => 'active',
        ]);

        $invoice = $this->invoice();

        $this->assertTrue(app(InvoiceEmailService::class)->send($invoice));

        $this->assertPushedWithPdf($invoice);
    }

    public function test_invoice_overdue_reminder_email_carries_a_downloadable_pdf_attachment(): void
    {
        $this->queueAndStorage();

        EmailTemplate::create([
            'name' => 'invoice_overdue_reminder',
            'subject' => 'Invoice {{invoice_no}} is overdue',
            'body' => 'Hi {{customer_name}}, please settle the balance.',
            'status' => 'active',
        ]);

        $invoice = $this->invoice();

        $this->assertTrue(app(InvoiceEmailService::class)->send($invoice, 'invoice_overdue_reminder'));

        $this->assertPushedWithPdf($invoice);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    private function queueAndStorage(): void
    {
        Queue::fake();
        Storage::fake('local');
    }

    private function invoice(): Invoice
    {
        $user = User::factory()->create();
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        return Invoice::create([
            'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
    }

    private function assertPushedWithPdf(Invoice $invoice): void
    {
        $path = "invoice-emails/invoice-{$invoice->invoice_no}.pdf";

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) use ($invoice, $path) {
            return $job->attachments !== []
                && $job->attachments[0]['filename'] === "invoice-{$invoice->invoice_no}.pdf"
                && $job->attachments[0]['disk'] === 'local'
                && $job->attachments[0]['path'] === $path
                && $job->attachments[0]['mimeType'] === 'application/pdf'
                && $job->attachments[0]['isInline'] === false
                && $job->attachments[0]['contentId'] === null;
        });

        $this->assertTrue(Storage::disk('local')->exists($path), 'The invoice PDF was not written to the local disk.');

        Storage::disk('local')->delete($path);
        $this->assertFalse(Storage::disk('local')->exists($path), 'The invoice PDF was not cleaned up by the test.');
    }
}
