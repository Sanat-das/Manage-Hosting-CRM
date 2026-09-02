@extends('adminlte::page')

@php
    $activeTab = (string) request()->query('tab', 'items');
    $tabs = [
        ['id' => 'items', 'label' => 'Line Items', 'icon' => 'bi bi-list-ul', 'badge' => $invoice->items->count()],
        ['id' => 'gst', 'label' => 'GST', 'icon' => 'bi bi-percent', 'badge' => ($gstBreakdown['tax'] ?? 0) > 0 ? '₹'.number_format((float) $gstBreakdown['tax'], 0) : null],
        ['id' => 'payments', 'label' => 'Payments', 'icon' => 'bi bi-cash-coin', 'badge' => $invoice->payments->count()],
        ['id' => 'summary', 'label' => 'Summary', 'icon' => 'bi bi-info-circle'],
    ];
    $dueAmount = max(0, (float) $invoice->total - (float) ($invoice->paid_amount ?? 0));
    $isOverdue = $dueAmount > 0 && $invoice->due_date && $invoice->due_date->isPast() && ! in_array($invoice->status, [\App\Models\Invoice::STATUS_PAID, \App\Models\Invoice::STATUS_VOID, \App\Models\Invoice::STATUS_CANCELLED], true);
    $isPaid = in_array($invoice->status, [\App\Models\Invoice::STATUS_PAID], true);
@endphp

@section('title', $invoice->invoice_no)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0 text-truncate" title="{{ $invoice->invoice_no }}">{{ $invoice->invoice_no }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.invoices.index') }}">Invoices</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $invoice->invoice_no }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if ($errors->has('send'))
        <x-adminlte-alert theme="danger" dismissible>{{ $errors->first('send') }}</x-adminlte-alert>
    @endif
    @if ($errors->has('payment'))
        <x-adminlte-alert theme="danger" dismissible>{{ $errors->first('payment') }}</x-adminlte-alert>
    @endif
    @if ($errors->any() && ! $errors->has('send') && ! $errors->has('payment'))
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    {{-- Header card --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                <i class="bi bi-receipt"></i>
            </div>
            <div class="flex-grow-1" style="min-width: 240px;">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $invoice->invoice_no }}</h4>
                    <x-adminlte.partials.status-badge :status="$invoice->status" />
                    <span class="badge text-bg-info">₹{{ number_format((float) $invoice->total, 2) }}</span>
                    @if ($isOverdue)
                        <span class="badge text-bg-danger">Overdue</span>
                    @elseif ($dueAmount > 0 && $dueAmount < (float) $invoice->total)
                        <span class="badge text-bg-warning">Partial</span>
                    @elseif ($isPaid)
                        <span class="badge text-bg-success">Paid</span>
                    @endif
                    @if ($invoice->due_date)
                        <span class="badge {{ $isOverdue ? 'text-bg-danger' : 'text-bg-secondary' }}">Due {{ $invoice->due_date->format('M j, Y') }}</span>
                    @endif
                </div>
                <div class="text-muted mt-1 d-flex flex-wrap align-items-center gap-2" style="font-size: var(--text-sm, 0.875rem);">
                    @if ($invoice->customer)
                        <span>
                            <i class="bi bi-person me-1"></i>
                            <a href="{{ route('admin.customers.show', $invoice->customer) }}" class="text-decoration-none">{{ $invoice->customer->full_name }}</a>
                            @if ($invoice->customer->user?->email)
                                <span class="text-muted">({{ $invoice->customer->user->email }})</span>
                            @endif
                        </span>
                        <span class="d-none d-sm-inline mx-1">|</span>
                    @endif
                    <span><i class="bi bi-calendar3 me-1"></i>Created {{ $invoice->created_at?->format('M j, Y') ?? '—' }}</span>
                    <span class="d-none d-sm-inline mx-1">|</span>
                    <span class="{{ $isOverdue ? 'text-danger fw-semibold' : '' }}"><i class="bi bi-calendar-x me-1"></i>Due {{ $invoice->due_date?->format('M j, Y') ?? '—' }}</span>
                    @if ($invoice->order_id)
                        <span class="d-none d-sm-inline mx-1">|</span>
                        <span><i class="bi bi-cart3 me-1"></i>Order #{{ $invoice->order_id }}</span>
                    @endif
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <a href="{{ route('admin.invoices.pdf', $invoice) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-file-pdf me-1"></i> PDF
                </a>
                @can('invoices.edit')
                    <a href="{{ route('admin.invoices.edit', $invoice) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                @endcan
                @can('invoices.edit')
                    @if (! in_array($invoice->status, [\App\Models\Invoice::STATUS_PAID, \App\Models\Invoice::STATUS_VOID, \App\Models\Invoice::STATUS_CANCELLED], true))
                        <form method="POST" action="{{ route('admin.invoices.send', $invoice) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-envelope me-1"></i> Send Invoice
                            </button>
                        </form>
                    @endif
                @endcan
                @can('payments.create')
                    @if (! in_array($invoice->status, [\App\Models\Invoice::STATUS_PAID, \App\Models\Invoice::STATUS_VOID, \App\Models\Invoice::STATUS_CANCELLED], true))
                        <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="collapse" data-bs-target="#recordPaymentForm" aria-expanded="false" aria-controls="recordPaymentForm">
                            <i class="bi bi-cash-coin me-1"></i> Record Payment
                        </button>
                    @endif
                @endcan
            </div>
        </div>
    </x-adminlte-card>

    {{-- Metric row --}}
    <x-adminlte.partials.metric-cards :items="[
        ['title' => '₹'.number_format((float) $invoice->total, 2), 'text' => 'Total', 'icon' => 'bi bi-receipt', 'theme' => 'primary'],
        ['title' => '₹'.number_format((float) ($invoice->paid_amount ?? 0), 2), 'text' => 'Paid', 'icon' => 'bi bi-check-circle', 'theme' => $isPaid ? 'success' : 'secondary'],
        ['title' => '₹'.number_format($dueAmount, 2), 'text' => 'Due', 'icon' => 'bi bi-exclamation-circle', 'theme' => $dueAmount > 0 ? 'danger' : 'success'],
        ['title' => ucfirst(str_replace('_', ' ', (string) $invoice->status)), 'text' => 'Status', 'icon' => 'bi bi-wallet2', 'theme' => $isPaid ? 'success' : ($isOverdue ? 'danger' : 'warning')],
    ]" />

    {{-- Record Payment collapse --}}
    @can('payments.create')
        @if (! in_array($invoice->status, [\App\Models\Invoice::STATUS_PAID, \App\Models\Invoice::STATUS_VOID, \App\Models\Invoice::STATUS_CANCELLED], true))
            <div class="collapse mb-3 {{ $errors->any() ? 'show' : '' }}" id="recordPaymentForm">
                <x-adminlte.partials.form-card icon="bi bi-cash-coin" title="Record Payment — {{ $invoice->invoice_no }}"
                    :action="route('admin.invoices.payment', $invoice)" submit-label="Record Payment" submit-icon="bi bi-check-lg">
                    <div class="row">
                        <div class="col-md-4">
                            <x-adminlte-input name="amount" type="number" step="0.01" min="0.01" label="Amount" value="{{ old('amount', number_format($invoice->dueAmount(), 2, '.', '')) }}" required />
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-select name="method" label="Method" required>
                                @foreach (\App\Models\Payment::METHOD_LABELS as $k => $v)
                                    <option value="{{ $k }}" @selected(old('method') === $k)>{{ $v }}</option>
                                @endforeach
                            </x-adminlte-select>
                        </div>
                        <div class="col-md-4">
                            <x-adminlte-input name="transaction_id" label="Transaction ID" value="{{ old('transaction_id') }}" placeholder="Optional" />
                        </div>
                    </div>
                    <x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes') }}</x-adminlte-textarea>
                </x-adminlte.partials.form-card>
            </div>
        @endif
    @endcan

    {{-- Tabbed detail --}}
    <x-adminlte-card>
        <x-adminlte.partials.detail-tabs :tabs="$tabs" :active-tab="$activeTab">
            {{-- Line Items --}}
            <div class="tab-pane fade {{ $activeTab === 'items' ? 'show active' : '' }}" id="items" role="tabpanel" aria-labelledby="items-tab">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h6 class="text-muted mb-0"><i class="bi bi-list-ul me-1"></i> Line Items <span class="badge text-bg-secondary ms-1">{{ $invoice->items->count() }}</span></h6>
                    <span class="text-muted small">Due ₹{{ number_format($dueAmount, 2) }} · Total ₹{{ number_format((float) $invoice->total, 2) }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Description</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($invoice->items as $item)
                                <tr>
                                    <td>{{ $item->description }}</td>
                                    <td class="text-center">{{ $item->quantity }}</td>
                                    <td class="text-end">₹{{ number_format((float) $item->unit_price, 2) }}</td>
                                    <td class="text-end fw-bold">₹{{ number_format((float) $item->total, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No items.</td></tr>
                            @endforelse
                        </tbody>
                        @if ($invoice->items->isNotEmpty())
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-end">Total</th>
                                    <th class="text-end">₹{{ number_format((float) $invoice->total, 2) }}</th>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            {{-- GST Breakdown --}}
            <div class="tab-pane fade {{ $activeTab === 'gst' ? 'show active' : '' }}" id="gst" role="tabpanel" aria-labelledby="gst-tab">
                @if (($gstBreakdown['tax'] ?? 0) > 0)
                    <div class="row text-center g-3">
                        @if (($gstBreakdown['type'] ?? '') === 'intra')
                            <div class="col-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="text-muted small">CGST</div>
                                    <div class="fs-5 fw-bold">₹{{ number_format((float) $gstBreakdown['cgst'], 2) }}</div>
                                    <div class="text-muted small">@ {{ $gstBreakdown['cgst_rate'] }}%</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="text-muted small">SGST</div>
                                    <div class="fs-5 fw-bold">₹{{ number_format((float) $gstBreakdown['sgst'], 2) }}</div>
                                    <div class="text-muted small">@ {{ $gstBreakdown['sgst_rate'] }}%</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="text-muted small">Total GST</div>
                                    <div class="fs-5 fw-bold">₹{{ number_format((float) $gstBreakdown['tax'], 2) }}</div>
                                    <div class="text-muted small">CGST + SGST</div>
                                </div>
                            </div>
                        @else
                            <div class="col-6">
                                <div class="border rounded p-3 h-100">
                                    <div class="text-muted small">IGST</div>
                                    <div class="fs-5 fw-bold">₹{{ number_format((float) $gstBreakdown['igst'], 2) }}</div>
                                    <div class="text-muted small">@ {{ $gstBreakdown['igst_rate'] }}%</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-3 h-100">
                                    <div class="text-muted small">Total GST</div>
                                    <div class="fs-5 fw-bold">₹{{ number_format((float) $gstBreakdown['tax'], 2) }}</div>
                                    <div class="text-muted small">IGST</div>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="mt-3 border rounded p-3 bg-light">
                        <div class="row g-2 small text-center">
                            <div class="col-md-3"><span class="text-muted">Subtotal</span><div class="fw-semibold">₹{{ number_format((float) $invoice->amount, 2) }}</div></div>
                            <div class="col-md-3"><span class="text-muted">Tax</span><div class="fw-semibold">₹{{ number_format((float) $invoice->tax, 2) }}</div></div>
                            <div class="col-md-3"><span class="text-muted">Discount</span><div class="fw-semibold">-₹{{ number_format((float) $invoice->discount, 2) }}</div></div>
                            <div class="col-md-3"><span class="text-muted">Total</span><div class="fw-bold">₹{{ number_format((float) $invoice->total, 2) }}</div></div>
                        </div>
                    </div>
                @else
                    <div class="text-center py-4 border rounded bg-light">
                        <i class="bi bi-percent text-muted" style="font-size: 1.5rem;"></i>
                        <p class="text-muted mb-1 mt-2">No GST applied.</p>
                        <p class="text-muted small mb-0">GST breakdown will appear here when tax is calculated.</p>
                    </div>
                @endif
            </div>

            {{-- Payments --}}
            <div class="tab-pane fade {{ $activeTab === 'payments' ? 'show active' : '' }}" id="payments" role="tabpanel" aria-labelledby="payments-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-muted mb-0"><i class="bi bi-cash-coin me-1"></i> Payment History</h6>
                    <span class="badge text-bg-secondary">{{ $invoice->payments->count() }} {{ \Illuminate\Support\Str::plural('payment', $invoice->payments->count()) }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr><th>Date</th><th>Method</th><th>Transaction ID</th><th class="text-end">Amount</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($invoice->payments as $payment)
                                <tr>
                                    <td class="text-muted">{{ $payment->created_at?->format('M j, Y H:i') }}</td>
                                    <td>{{ \App\Models\Payment::METHOD_LABELS[$payment->method] ?? ucfirst(str_replace('_', ' ', $payment->method)) }}</td>
                                    <td class="text-muted">{{ $payment->transaction_id ?? '—' }}</td>
                                    <td class="text-end fw-bold">₹{{ number_format((float) $payment->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No payments recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Summary --}}
            <div class="tab-pane fade {{ $activeTab === 'summary' ? 'show active' : '' }}" id="summary" role="tabpanel" aria-labelledby="summary-tab">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3"><i class="bi bi-info-circle me-1"></i> Invoice Summary</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <th class="w-25 text-muted">Customer</th>
                                    <td>
                                        @if ($invoice->customer)
                                            <a href="{{ route('admin.customers.show', $invoice->customer) }}" class="text-decoration-none">{{ $invoice->customer->full_name }}</a>
                                            @if ($invoice->customer->user?->email)
                                                <div class="text-muted small">{{ $invoice->customer->user->email }}</div>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                                <tr><th class="text-muted">Subtotal</th><td class="text-end">₹{{ number_format((float) $invoice->amount, 2) }}</td></tr>
                                <tr><th class="text-muted">Tax</th><td class="text-end">₹{{ number_format((float) $invoice->tax, 2) }}</td></tr>
                                <tr><th class="text-muted">Discount</th><td class="text-end">-₹{{ number_format((float) $invoice->discount, 2) }}</td></tr>
                                <tr class="border-top"><th>Total</th><td class="text-end fw-bold">₹{{ number_format((float) $invoice->total, 2) }}</td></tr>
                                <tr><th class="text-muted">Paid</th><td class="text-end text-success">₹{{ number_format((float) ($invoice->paid_amount ?? 0), 2) }}</td></tr>
                                <tr>
                                    <th>Due</th>
                                    <td class="text-end fw-bold {{ $dueAmount > 0 ? 'text-danger' : 'text-success' }}">₹{{ number_format($dueAmount, 2) }}</td>
                                </tr>
                                <tr><th class="text-muted">Due date</th><td class="{{ $isOverdue ? 'text-danger fw-bold' : '' }}">{{ $invoice->due_date?->format('M j, Y') ?? '—' }} @if ($isOverdue)<span class="badge text-bg-danger ms-1">Overdue</span>@endif</td></tr>
                                <tr><th class="text-muted">Created</th><td>{{ $invoice->created_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$invoice->status" /></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3"><i class="bi bi-sticky me-1"></i> Notes &amp; Details</h6>
                        @if ($invoice->notes)
                            <div class="border rounded p-3 bg-light mb-3">
                                <p class="mb-0" style="white-space: pre-wrap;">{{ $invoice->notes }}</p>
                            </div>
                        @else
                            <div class="text-center py-4 border rounded bg-light mb-3">
                                <i class="bi bi-sticky text-muted" style="font-size: 1.5rem;"></i>
                                <p class="text-muted mb-0 mt-2">No notes.</p>
                            </div>
                        @endif
                        <div class="border rounded p-3">
                            <div class="row g-2 small">
                                <div class="col-6"><span class="text-muted">Invoice #</span><div class="fw-semibold">{{ $invoice->invoice_no }}</div></div>
                                <div class="col-6"><span class="text-muted">Order</span><div>{{ $invoice->order_id ? '#'.$invoice->order_id : '—' }}</div></div>
                                <div class="col-6"><span class="text-muted">Currency</span><div>INR</div></div>
                                <div class="col-6"><span class="text-muted">Updated</span><div>{{ $invoice->updated_at?->format('M j, Y H:i') ?? '—' }}</div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-adminlte.partials.detail-tabs>
    </x-adminlte-card>
@stop
