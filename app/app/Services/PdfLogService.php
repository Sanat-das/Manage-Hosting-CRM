<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoicePdfLog;
use App\Models\User;

/**
 * Audit trail for invoice PDF generation. Each admin "download PDF" action
 * inserts a row into invoice_pdf_log without touching the rendered bytes.
 */
class PdfLogService
{
    /**
     * @param  array<string, mixed>|null  $meta  optional pdf_bytes / file_size / mime_title
     */
    public function record(Invoice $invoice, ?array $meta = null, ?User $user = null): InvoicePdfLog
    {
        $meta ??= [];

        $fileSize = $meta['file_size'] ?? (isset($meta['pdf_bytes']) ? strlen($meta['pdf_bytes']) : null);

        return InvoicePdfLog::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'generated_by' => $user?->id,
            'file_name' => "invoice-{$invoice->invoice_no}.pdf",
            'file_size' => $fileSize,
            'file_path' => '',
            'mime_title' => $meta['mime_title'] ?? null,
        ]);
    }
}
