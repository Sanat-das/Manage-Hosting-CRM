<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\BillingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin invoice management.
 *
 * Route contract: routes/admin/billing.php
 * Permission gates: invoices.view / invoices.create / invoices.edit
 */
class InvoiceController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly BillingService $billing)
    {
    }

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

    public function create(): View
    {
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

        return view('admin.invoices.edit', compact('invoice', 'customers'));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:draft,sent,overdue,cancelled,void'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $invoice->update($validated);

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', 'Invoice updated.');
    }

    public function pdf(Invoice $invoice): \Symfony\Component\HttpFoundation\Response
    {
        $invoice->load(['customer.user', 'items']);
        $gstBreakdown = $invoice->gst_breakdown;

        $pdf = Pdf::loadView('admin.invoices.pdf', compact('invoice', 'gstBreakdown'))
            ->setPaper('a4');

        return $pdf->download("invoice-{$invoice->invoice_no}.pdf");
    }
}
