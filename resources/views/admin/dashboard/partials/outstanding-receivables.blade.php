@php
    $hasOutstanding = $receivables->outstandingTotal > 0;
@endphp

<x-adminlte-card icon="bi bi-cash-coin" title="Outstanding receivables">
    <x-slot:tools>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" data-widget-toggle title="Hide widget" aria-pressed="false" style="border-radius: var(--radius-md); font-size: var(--text-xs);"><i class="bi bi-eye"></i><span class="visually-hidden">Hide widget</span></button>
        @can('reports.view')
            <a href="{{ route('admin.reports.revenue') }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: var(--radius-md); font-size: var(--text-xs);">View all</a>
        @endcan
    </x-slot:tools>

    @if($hasOutstanding)
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 px-3 py-2 mb-3" style="background: var(--color-bg-subtle); border-radius: var(--radius-lg);">
            <div class="d-flex align-items-center gap-3">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 2.5rem; height: 2.5rem; background: color-mix(in srgb, var(--color-warning) 14%, var(--bs-body-bg)); color: var(--color-warning); font-size: 1.1rem;"><i class="bi bi-cash-coin"></i></span>
                <div>
                    <div class="text-muted" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase; font-weight: 600;">Total outstanding</div>
                    <div class="fw-bold" style="font-size: var(--text-xl); letter-spacing: var(--tracking-tight); line-height: 1; font-variant-numeric: tabular-nums;"><x-adminlte.partials.currency :value="$receivables->outstandingTotal" /></div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <span class="text-danger fw-semibold" style="font-size: var(--text-sm); font-variant-numeric: tabular-nums;">Overdue <x-adminlte.partials.currency :value="$receivables->overdueTotal" /></span>
                <span class="text-muted" style="font-size: var(--text-sm); font-variant-numeric: tabular-nums;">Current due <x-adminlte.partials.currency :value="$receivables->currentTotal" /></span>
                <span class="badge rounded-pill text-bg-danger" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.28em 0.62em;">{{ $receivables->overdueCount }} overdue</span>
                @if($receivables->oldestOverdueDays > 0)
                    <span class="text-muted" style="font-size: var(--text-xs); white-space: nowrap;">Oldest {{ $receivables->oldestOverdueDays }} days overdue</span>
                @endif
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size: var(--text-sm);">
                <thead>
                    <tr class="text-muted" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase;">
                        <th class="border-0 fw-semibold">Invoice</th>
                        <th class="border-0 fw-semibold">Customer</th>
                        <th class="border-0 fw-semibold">Due date</th>
                        <th class="border-0 fw-semibold text-end" style="font-variant-numeric: tabular-nums;">Amount owed</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($receivables->topOverdue as $invoice)
                        <tr>
                            <td class="py-2 fw-semibold" style="font-variant-numeric: tabular-nums;">{{ $invoice->invoice_no ?? '#'.$invoice->id }}</td>
                            <td class="py-2" style="max-width: 10rem; overflow-wrap: anywhere;">{{ $invoice->customer?->full_name ?? '—' }}</td>
                            <td class="py-2" style="font-variant-numeric: tabular-nums;">
                                <span class="text-danger fw-semibold">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</span>
                                @if($invoice->due_date)
                                    <div class="text-muted" style="font-size: var(--text-xs);">{{ max(0, (int) $invoice->due_date->diffInDays(now())) }} days overdue</div>
                                @endif
                            </td>
                            <td class="py-2 text-end" style="font-variant-numeric: tabular-nums;"><x-adminlte.partials.currency :value="$invoice->dueAmount()" /></td>
                        </tr>
                    @empty
                        <x-ui.empty-table-row
                            :col-span="4"
                            icon="bi bi-cash-coin"
                            title="No overdue invoices"
                            message="Outstanding amounts are still within their due date — nothing is past due right now."
                            size="sm"
                        />
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <x-adminlte.partials.empty-state
            icon="bi bi-cash-coin"
            title="No outstanding receivables"
            message="Every issued invoice has been settled in full. New unpaid invoices will surface here with the amount owed."
            size="sm"
        />
    @endif
</x-adminlte-card>
