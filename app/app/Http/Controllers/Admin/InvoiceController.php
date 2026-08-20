<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Billing\BillingService;
use App\Services\InvoiceEmailService;
use App\Services\PdfLogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin invoice management.
 *
 * Route contract: routes/admin/billing.php
 * Permission gates: invoices.view / invoices.create / invoices.edit
 */
class InvoiceController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly BillingService $billing,
        private readonly PdfLogService $pdfLog,
        private readonly InvoiceEmailService $invoiceEmails,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $invoices = Invoice::query()
            ->with(['customer.user'])
            ->when($status !== '' && $status !== null, fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('invoice_no', 'like', "%{$search}%")
                        ->orWhereHas('customer.user', fn ($u) => $u->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $statuses = Invoice::STATUS_LABELS;
        $stats = Invoice::query()->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');

        return view('admin.invoices.index', compact('invoices', 'search', 'status', 'statuses', 'stats'));
    }

    public function create(Request $request): View
    {
        // Preselect the customer when arriving from the customer profile
        // ("New Invoice" quick action): flash the query value so old() picks it up.
        if ($customerId = $request->query('customer_id')) {
            $request->flashOnly('customer_id');
        }

        $customers = Customer::with('user')->orderBy('id')->get();

        return view('admin.invoices.create', compact('customers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'status' => ['required', 'in:draft,sent'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $items = collect($validated['items'])->map(fn ($item) => [
            'description' => $item['description'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'total' => (float) $item['quantity'] * (float) $item['unit_price'],
        ])->toArray();

        $invoice = $this->billing->createWithItems([
            'customer_id' => $validated['customer_id'],
            'amount' => $validated['amount'],
            'status' => $validated['status'],
            'due_date' => $validated['due_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ], $items);

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->invoice_no} created.");
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['customer.user', 'items', 'payments']);
        $gstBreakdown = $invoice->gst_breakdown;

        return view('admin.invoices.show', compact('invoice', 'gstBreakdown'));
    }

    public function edit(Invoice $invoice): View
    {
        $invoice->load('items');
        $customers = Customer::with('user')->orderBy('id')->get();

        return view('admin.invoices.edit', [
            'invoice' => $invoice,
            'customers' => $customers,
            'locked' => $invoice->isAmountLocked(),
            'allowedStatuses' => $invoice->allowedStatusTransitions(),
        ]);
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        // Amounts are frozen once money moved (payments recorded or a settled/
        // terminal status): only status/due_date/notes remain editable.
        if ($invoice->isAmountLocked()) {
            $validated = $request->validate([
                'status' => ['sometimes', 'string', Rule::in(array_keys($invoice->allowedStatusTransitions()))],
                'due_date' => ['nullable', 'date'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);

            $invoice->update($validated);

            return redirect()
                ->route('admin.invoices.show', $invoice)
                ->with('success', 'Invoice updated.');
        }

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'string', Rule::in(array_keys($invoice->allowedStatusTransitions()))],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $items = collect($validated['items'])->map(fn ($item) => [
            'description' => $item['description'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'total' => (float) $item['quantity'] * (float) $item['unit_price'],
        ])->toArray();

        $amount = round(array_sum(array_column($items, 'total')), 2);

        $this->billing->updateWithItems($invoice, [
            'customer_id' => $validated['customer_id'],
            'amount' => $amount,
            'discount' => $validated['discount'] ?? 0,
            'status' => $validated['status'],
            'due_date' => $validated['due_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ], $items);

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->invoice_no} updated.");
    }

    /**
     * Record a payment directly against an invoice from the invoice page.
     *
     * Reuses the shared billing engine (recordPayment) so partial, full and
     * overpayment flows behave exactly like the standalone payment form; the
     * user is returned to the invoice instead of the payments index.
     */
    public function storePayment(Request $request, Invoice $invoice): RedirectResponse
    {
        if (in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_VOID, Invoice::STATUS_CANCELLED], true)) {
            return back()->withErrors(['payment' => 'Payments cannot be recorded against a paid, void or cancelled invoice.']);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'max:50'],
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->billing->recordPayment(
            $invoice->id,
            (float) $validated['amount'],
            $validated['method'],
            $validated['transaction_id'] ?? ''
        );

        $message = match ($result['status']) {
            'overpaid' => "Payment recorded. Overpayment of {$result['overpayment']} credited to customer.",
            'paid' => 'Invoice fully paid.',
            default => "Payment recorded. Remaining due: {$result['remaining_due']}",
        };

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', $message);
    }

    /**
     * Email the invoice to the customer from the invoice page.
     *
     * A draft invoice is flipped to 'sent' so the status reflects that the
     * customer has been notified; re-sending an already-sent/overdue invoice
     * emails again without changing its status. Paid, void and cancelled
     * invoices cannot be emailed, mirroring the storePayment guard.
     */
    public function send(Invoice $invoice): RedirectResponse
    {
        if (in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_VOID, Invoice::STATUS_CANCELLED], true)) {
            return back()->withErrors(['send' => 'A paid, void or cancelled invoice cannot be emailed.']);
        }

        if (! $this->invoiceEmails->send($invoice)) {
            return back()->withErrors(['send' => 'Invoice email skipped: no active "invoice_created" template or no customer email on file.']);
        }

        // Status only flips once the email was actually dispatched, so a
        // failed send never marks the invoice as notified.
        if ($invoice->isDraft()) {
            $invoice->update(['status' => Invoice::STATUS_SENT]);
        }

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->invoice_no} sent to the customer.");
    }

    public function pdf(Request $request, Invoice $invoice): Response
    {
        $invoice->load(['customer.user', 'items']);
        $gstBreakdown = $invoice->gst_breakdown;

        $pdf = Pdf::loadView('admin.invoices.pdf', compact('invoice', 'gstBreakdown'))
            ->setPaper('a4');

        $bytes = $pdf->output();
        $this->pdfLog->record($invoice, ['pdf_bytes' => $bytes], $request->user());

        return $pdf->download("invoice-{$invoice->invoice_no}.pdf");
    }
}
