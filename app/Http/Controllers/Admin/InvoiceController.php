<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InvoiceRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Exports\CsvStreamService;
use App\Services\InvoiceEmailService;
use App\Services\PdfLogService;
use App\Support\AppSettings;
use App\Support\Audit\AuditRecorder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): View|StreamedResponse
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $query = Invoice::query()
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
            ->gridSort([
                'invoice_no' => 'invoice_no',
                'customer' => fn (Builder $q, string $dir) => $q->orderBy(Customer::select('company')->whereColumn('customers.id', 'invoices.customer_id'), $dir),
                'amount' => 'amount',
                'tax' => 'tax',
                'total' => 'total',
                'paid_amount' => 'paid_amount',
                'status' => 'status',
                'due_date' => 'due_date',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id');

        if ($request->query('export') === 'csv') {
            $filename = 'invoices-'.now()->format('Y-m-d_His').'.csv';
            $csvHeaders = ['Invoice #', 'Customer', 'Email', 'Company', 'Amount', 'Tax', 'Total', 'Paid Amount', 'Status', 'Due Date', 'Created At'];

            /** @var CsvStreamService $csv */
            $csv = app(CsvStreamService::class);

            return $csv->stream($filename, $csvHeaders, function ($handle) use ($query): void {
                $query->chunk(500, function ($invoices) use ($handle): void {
                    foreach ($invoices as $invoice) {
                        fputcsv($handle, [
                            $invoice->invoice_no,
                            $invoice->customer?->full_name ?? '',
                            $invoice->customer?->user?->email ?? '',
                            $invoice->customer?->company ?? '',
                            number_format((float) $invoice->amount, 2, '.', ''),
                            number_format((float) $invoice->tax, 2, '.', ''),
                            number_format((float) $invoice->total, 2, '.', ''),
                            number_format((float) ($invoice->paid_amount ?? 0), 2, '.', ''),
                            $invoice->status,
                            $invoice->due_date?->format('Y-m-d') ?? '',
                            $invoice->created_at?->format('Y-m-d H:i:s') ?? '',
                        ]);
                    }
                });
            });
        }

        $invoices = $query->paginate(self::PER_PAGE)->withQueryString();

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

    public function store(InvoiceRequest $request): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $validated = $request->validated();

        $items = collect($validated['items'])->map(fn ($item) => [
            'description' => $item['description'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'total' => (float) $item['quantity'] * (float) $item['unit_price'],
        ])->toArray();

        // The header amount is derived from the line items, never posted by
        // the browser: two sources of truth let a stored invoice disagree with
        // its own lines, and the first edit then changed the total silently.
        $amount = round(array_sum(array_column($items, 'total')), 2);
        $discount = (float) ($validated['discount'] ?? 0);

        $invoice = $this->billing->createWithItems([
            'customer_id' => $validated['customer_id'],
            'amount' => $amount,
            'discount' => $discount,
            'status' => $validated['status'],
            'due_date' => $validated['due_date'] ?? $this->defaultDueDate(),
            'notes' => $validated['notes'] ?? null,
        ], $items, $this->billing->resolvePlaceOfSupply((int) $validated['customer_id']));

        $this->audit->activity(
            'invoice.created',
            customer: $invoice->customer,
            metadata: ['invoice' => $this->auditSnapshot($invoice)],
            description: "Invoice {$invoice->invoice_no} created",
            subject: $invoice,
        );

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
        $invoice->load('items', 'customer.user');

        return view('admin.invoices.edit', [
            'invoice' => $invoice,
            'locked' => $invoice->isAmountLocked(),
            'editableStatuses' => $invoice->editableStatusTransitions(),
            'submittedItems' => $this->submittedItems(),
            'lastEdit' => $this->lastEdit($invoice),
        ]);
    }

    /**
     * The line rows the form shows after a failed validation: what was
     * submitted, so a line the admin added is not silently dropped on the
     * way back.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function submittedItems(): ?array
    {
        $items = session()->getOldInput('items');

        if (! is_array($items) || $items === []) {
            return null;
        }

        return array_values($items);
    }

    /**
     * Who last touched this invoice, and when — the audit trail read back.
     */
    private function lastEdit(Invoice $invoice): ?string
    {
        $log = ActivityLog::query()
            ->where('subject_type', $invoice->getMorphClass())
            ->where('subject_id', $invoice->id)
            ->whereIn('action', ['invoice.updated', 'invoice.created'])
            ->latest('id')
            ->first();

        if ($log === null) {
            return null;
        }

        $actor = $log->user_id !== null ? User::find($log->user_id) : null;
        $name = $actor?->name ?? 'Someone';

        return trim($name.' · '.$log->created_at?->format('j M Y, H:i'));
    }

    public function update(InvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);

        $previousStatus = $invoice->status;
        $before = $this->auditSnapshot($invoice);

        // Amounts are frozen once money moved (payments recorded or a settled/
        // terminal status): only status/due_date/notes remain editable.
        if ($invoice->isAmountLocked()) {
            $validated = $request->validated();

            $payload = [];

            if (array_key_exists('status', $validated)) {
                $payload['status'] = $validated['status'];
            }

            if (array_key_exists('notes', $validated)) {
                $payload['notes'] = $validated['notes'];
            }

            // A blank due date must never be written: the column is NOT NULL.
            // An absent field keeps the stored date; a cleared one falls back
            // to the payment term.
            $payload['due_date'] = $validated['due_date']
                ?? $invoice->due_date?->toDateString()
                ?? $this->defaultDueDate();

            $invoice->update($payload);

            $this->rewindScheduleIfVoided($invoice, $previousStatus);
            $this->auditEdit($invoice, $before);

            return redirect()
                ->route('admin.invoices.show', $invoice)
                ->with('success', 'Invoice updated.');
        }

        $validated = $request->validated();

        $items = collect($validated['items'])->map(function (array $item) {
            $line = [
                'description' => $item['description'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'total' => (float) $item['quantity'] * (float) $item['unit_price'],
            ];

            if (isset($item['id']) && $item['id'] !== '') {
                $line['id'] = (int) $item['id'];
            }

            return $line;
        })->toArray();

        $amount = round(array_sum(array_column($items, 'total')), 2);

        // An absent discount field keeps the stored one; a posted one (even a
        // cleared one) replaces it.
        $discount = array_key_exists('discount', $validated)
            ? (float) $validated['discount']
            : (float) $invoice->discount;

        $this->billing->updateWithItems($invoice, [
            'amount' => $amount,
            'discount' => $discount,
            'status' => $validated['status'],
            'due_date' => $validated['due_date']
                ?? $invoice->due_date?->toDateString()
                ?? $this->defaultDueDate(),
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $invoice->notes,
        ], $items, $this->billing->resolvePlaceOfSupply((int) $invoice->customer_id));

        $this->rewindScheduleIfVoided($invoice, $previousStatus);
        $this->auditEdit($invoice, $before);

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->invoice_no} updated.");
    }

    /**
     * Write the audit row for an invoice edit: who changed it, and what the
     * money fields were before and after.
     */
    private function auditEdit(Invoice $invoice, array $before): void
    {
        $this->audit->activity(
            'invoice.updated',
            customer: $invoice->customer,
            metadata: [
                'before' => $before,
                'after' => $this->auditSnapshot($invoice->fresh()),
            ],
            description: "Invoice {$invoice->invoice_no} updated",
            subject: $invoice,
        );
    }

    /**
     * The money fields of an invoice, for the audit trail.
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(Invoice $invoice): array
    {
        return [
            'status' => $invoice->status,
            'amount' => (float) $invoice->amount,
            'tax' => (float) $invoice->tax,
            'discount' => (float) $invoice->discount,
            'total' => (float) $invoice->total,
            'due_date' => $invoice->due_date?->toDateString(),
            'lines' => $invoice->items()->count(),
        ];
    }

    /**
     * A void or a cancellation hands the billed period back: the renewal
     * engine advanced the service's billing schedule when it issued the
     * invoice, and an invoice that never stands should not keep the period.
     */
    private function rewindScheduleIfVoided(Invoice $invoice, string $previousStatus): void
    {
        if ($invoice->status === $previousStatus) {
            return;
        }

        if (! in_array($invoice->status, [Invoice::STATUS_VOID, Invoice::STATUS_CANCELLED], true)) {
            return;
        }

        $this->billing->rewindBillingSchedule($invoice);
    }

    /**
     * The due date a new invoice gets when the form leaves it blank: the
     * configured payment term. The column is NOT NULL, so an invoice without
     * a due date cannot exist.
     */
    private function defaultDueDate(): string
    {
        $days = max(0, (int) AppSettings::get('due_days', '7'));

        return now()->addDays($days)->toDateString();
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
        if (in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_VOID, Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED], true)) {
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
        if (in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_VOID, Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED], true)) {
            return back()->withErrors(['send' => 'A paid, void or cancelled invoice cannot be emailed.']);
        }

        if (! $this->invoiceEmails->send($invoice, bypassMatrix: true)) {
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

    public function refund(Request $request, Invoice $invoice): RedirectResponse
    {
        if (! in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_PARTIAL], true)) {
            return back()->withErrors(['refund' => 'Only paid or partially paid invoices can be refunded.']);
        }
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->billing->refund($invoice, $validated['reason'] ?? null);

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->invoice_no} marked refunded.");
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
