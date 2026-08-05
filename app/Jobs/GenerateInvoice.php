<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Billing\BillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate an invoice for a hosting account / domain renewal.
 */
class GenerateInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $customerId,
        public string $description,
        public float $amount,
        public ?int $orderId = null,
    ) {
        $this->onQueue('billing');
    }

    public function handle(BillingService $billing): void
    {
        Log::info('Generating invoice', [
            'customer_id' => $this->customerId,
            'description' => $this->description,
            'amount' => $this->amount,
        ]);

        $invoiceNo = 'INV-' . str_pad((string) (Invoice::max('id') + 1), 6, '0', STR_PAD_LEFT);

        Invoice::create([
            'invoice_no' => $invoiceNo,
            'customer_id' => $this->customerId,
            'order_id' => $this->orderId,
            'amount' => $this->amount,
            'tax' => 0,
            'tax_rate' => 0,
            'discount' => 0,
            'total' => $this->amount,
            'status' => 'sent',
            'due_date' => now()->addDays(7),
            'notes' => $this->description,
        ]);

        Log::info('Invoice generated', ['invoice_no' => $invoiceNo]);
    }
}
