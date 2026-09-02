<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Billing\BillingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin payment management.
 *
 * Route contract: routes/admin/billing.php
 * Permission gates: payments.view / payments.create
 */
class PaymentController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly BillingService $billing) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $method = $request->query('method');

        $payments = Payment::query()
            ->with(['invoice.customer.user'])
            ->when($method !== '' && $method !== null, fn ($q) => $q->where('method', $method))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('transaction_id', 'like', "%{$search}%")
                        ->orWhereHas('invoice', fn ($i) => $i->where('invoice_no', 'like', "%{$search}%"));
                });
            })
            ->gridSort([
                'id' => 'id',
                'invoice' => 'invoice.invoice_no',
                'customer' => fn (Builder $q, string $dir) => $q->orderBy(Invoice::select('customers.company')->join('customers', 'customers.id', '=', 'invoices.customer_id')->whereColumn('invoices.id', 'payments.invoice_id'), $dir),
                'method' => 'method',
                'transaction_id' => 'transaction_id',
                'amount' => 'amount',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $methods = Payment::METHOD_LABELS;

        return view('admin.payments.index', compact('payments', 'search', 'method', 'methods'));
    }

    public function create(): View
    {
        $invoices = Invoice::whereNotIn('status', ['paid', 'void', 'cancelled'])
            ->with('customer.user')
            ->orderByDesc('id')
            ->get();

        return view('admin.payments.create', compact('invoices'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'max:50'],
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->billing->recordPayment(
            $validated['invoice_id'],
            $validated['amount'],
            $validated['method'],
            $validated['transaction_id'] ?? ''
        );

        $message = match ($result['status']) {
            'overpaid' => "Payment recorded. Overpayment of {$result['overpayment']} credited to customer.",
            'paid' => 'Invoice fully paid.',
            default => "Payment recorded. Remaining due: {$result['remaining_due']}",
        };

        return redirect()
            ->route('admin.payments.index')
            ->with('success', $message);
    }

    public function show(Payment $payment): View
    {
        $payment->load(['invoice.customer.user']);

        return view('admin.payments.show', compact('payment'));
    }
}
