@props(['invoices' => []])

@php
    $value = fn ($row, $key, $default = null) => data_get($row, $key, $default);
    $invoiceId = fn ($invoice) => is_array($invoice) ? ($invoice['id'] ?? null) : ($invoice->id ?? null);
    $fmtDate = function ($date) {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('M j, Y');
        }
        if (is_string($date) && $date !== '') {
            return substr($date, 0, 10);
        }
        return '—';
    };
@endphp

@if (empty($invoices))
    <p class="text-muted mb-0">No pending invoices.</p>
@else
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th>Due</th>
                    <th class="text-end">Amount due</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    @php
                        $status = $value($invoice, 'status', 'sent');
                        $overdue = $status === 'overdue' || $value($invoice, 'is_overdue', false);
                        $statusColors = ['overdue' => 'danger', 'sent' => 'warning', 'partial' => 'warning'];
                        $statusColor = $statusColors[$status] ?? 'warning';
                    @endphp
                    <tr>
                        <td>
                            @if (Route::has('admin.invoices.show') && $invoiceId($invoice))
                                <a href="{{ route('admin.invoices.show', $invoiceId($invoice)) }}"><strong>{{ $value($invoice, 'invoice_no', '—') }}</strong></a>
                            @else
                                <strong>{{ $value($invoice, 'invoice_no', '—') }}</strong>
                            @endif
                        </td>
                        <td>{{ $value($invoice, 'customer_name') ?? $value($invoice, 'customer.full_name') ?? '—' }}</td>
                        <td class="text-muted">{{ $fmtDate($value($invoice, 'due_date')) }}</td>
                        <td class="text-end fw-bold {{ $overdue ? 'text-danger' : 'text-warning' }}">
                            ₹{{ number_format((float) $value($invoice, 'due_amount', $value($invoice, 'total', 0)), 2) }}
                        </td>
                        <td><span class="badge bg-{{ $statusColor }}">{{ ucfirst($status) }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-2 text-end">
        @if (Route::has('admin.invoices.index'))
            <a href="{{ route('admin.invoices.index') }}" class="small">View all invoices <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        @endif
    </div>
@endif
