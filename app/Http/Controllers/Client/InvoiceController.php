<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Client portal — invoice listing, detail, and PDF download.
 */
class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $status = $request->query('status');
        $search = trim((string) $request->query('search'));

        $invoices = $customer->invoices()
            ->with(['items', 'payments'])
            ->when($status === 'unpaid', fn ($q) => $q->whereIn('status', ['draft', 'sent', 'overdue', 'partial']))
            ->when($status && $status !== 'unpaid', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where('invoice_no', 'like', "%{$search}%"))
            ->gridSort([
                'invoice_no' => 'invoice_no',
                'created_at' => 'created_at',
                'due_date' => 'due_date',
                'total' => 'total',
                'status' => 'status',
            ])
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('client.invoices.index', compact('invoices', 'status', 'search'));
    }

    public function show(Request $request, int $id): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $invoice = $customer->invoices()
            ->with(['items', 'payments'])
            ->findOrFail($id);

        return view('client.invoices.show', compact('invoice'));
    }

    /**
     * Download invoice as PDF.
     */
    public function pdf(Request $request, Invoice $invoice): Response
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);
        abort_unless($invoice->customer_id === $customer->id, 403);

        $invoice->load(['items', 'customer']);
        $gstBreakdown = $invoice->gst_breakdown;

        $pdf = Pdf::loadView('admin.invoices.pdf', compact('invoice', 'gstBreakdown'));

        return $pdf->download("invoice-{$invoice->invoice_no}.pdf");
    }
}
