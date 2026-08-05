@extends('adminlte::page')

@section('title', $order->order_no)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $order->order_no }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.orders.index') }}">Orders</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $order->order_no }}</li>
            </ol>
        </div>
    </div>
@stop

@php
    $activeTab = (string) request()->query('tab', 'order-info');
    $tabs = [
        ['id' => 'order-info', 'label' => 'Order Info', 'icon' => 'bi bi-receipt'],
        ['id' => 'items', 'label' => 'Items', 'icon' => 'bi bi-box-seam', 'badge' => $order->items->count()],
        ['id' => 'status-history', 'label' => 'Status History', 'icon' => 'bi bi-clock-history', 'badge' => $activity->count()],
    ];

    // Mirrors OrderController::TRANSITIONS minus the hidden `active → suspended`
    // move (reserved for the hosting module). status values are the DB enum.
    $transitionMap = [
        'pending' => ['active' => 'Activate', 'cancelled' => 'Cancel'],
        'suspended' => ['active' => 'Activate', 'cancelled' => 'Cancel'],
    ];
    $allowedTransitions = $transitionMap[$order->status] ?? [];

    $billingCycleLabels = [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'semi_annual' => 'Semi-Annual',
        'annual' => 'Annual',
        'biennial' => 'Biennial',
        'one_time' => 'One Time',
    ];
    $cycleLabel = $billingCycleLabels[$order->billing_cycle] ?? ucfirst(str_replace('_', ' ', (string) $order->billing_cycle));
@endphp

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    {{-- Order header --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                <i class="bi bi-cart3"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $order->order_no }}</h4>
                    <x-adminlte.partials.status-badge :status="$order->status" />
                    @if ($order->billing_cycle)
                        <span class="badge bg-info">{{ $cycleLabel }}</span>
                    @endif
                </div>
                <div class="text-muted mt-1">
                    @if ($order->customer)
                        <a href="{{ route('admin.customers.show', $order->customer) }}">
                            <i class="bi bi-person me-1"></i>{{ $order->customer->full_name }}
                        </a>
                        @if ($order->customer->user?->email)
                            <span class="mx-2">|</span>{{ $order->customer->user->email }}
                        @endif
                    @endif
                    <span class="mx-2">|</span>
                    <i class="bi bi-calendar3 me-1"></i>Ordered {{ $order->created_at?->format('M j, Y H:i') }}
                </div>
            </div>
            @can('orders.edit')
                @if ($allowedTransitions)
                    <div class="d-flex gap-2">
                        @foreach ($allowedTransitions as $target => $label)
                            <form method="POST" action="{{ route('admin.orders.status', $order) }}"
                                  onsubmit="return confirm('{{ $label }} order {{ $order->order_no }}?');"
                                  class="d-inline">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="status" value="{{ $target }}">
                                <button type="submit"
                                        class="btn btn-sm {{ $target === 'cancelled' ? 'btn-outline-danger' : 'btn-success' }}">
                                    <i class="bi {{ $target === 'cancelled' ? 'bi-x-circle' : 'bi-check-lg' }} me-1"></i>
                                    {{ $label }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif
            @endcan
        </div>
    </x-adminlte-card>

    {{-- Tabbed detail --}}
    <x-adminlte-card>
        <x-adminlte.partials.detail-tabs :tabs="$tabs" :active-tab="$activeTab">
            {{-- Order Info --}}
            <div class="tab-pane fade {{ $activeTab === 'order-info' ? 'show active' : '' }}" id="order-info"
                 role="tabpanel" aria-labelledby="order-info-tab">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Order number</th><td>{{ $order->order_no }}</td></tr>
                                <tr><th class="text-muted">Customer</th><td>{{ $order->customer?->full_name ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Product</th><td>{{ $order->product?->name ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Billing cycle</th><td>{{ $cycleLabel }}</td></tr>
                                <tr><th class="text-muted">Quantity</th><td>{{ $order->quantity }}</td></tr>
                                <tr><th class="text-muted">Total</th><td class="fw-bold">₹{{ number_format((float) $order->total, 2) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr>
                                    <th class="w-25 text-muted">Status</th>
                                    <td><x-adminlte.partials.status-badge :status="$order->status" /></td>
                                </tr>
                                <tr><th class="text-muted">Domain</th><td>{{ $order->domain_name ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Next billing</th><td>{{ $order->next_billing_date?->format('M j, Y') ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Last billing</th><td>{{ $order->last_billing_date?->format('M j, Y') ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Created</th><td>{{ $order->created_at?->format('M j, Y H:i') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($order->notes)
                    <div class="mt-2">
                        <strong class="text-muted small d-block">Notes</strong>
                        <p class="mb-0">{{ $order->notes }}</p>
                    </div>
                @endif

                {{-- Related records (controller eager-loads invoices/hostingAccount/domain) --}}
                <div class="row mt-3">
                    <div class="col-md-4">
                        <h6 class="text-muted text-uppercase small mb-2"><i class="bi bi-receipt me-1"></i> Invoices</h6>
                        @forelse ($order->invoices as $invoice)
                            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                <div>
                                    <strong>{{ $invoice->invoice_no }}</strong>
                                    <div class="text-muted small">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</div>
                                </div>
                                <div class="text-end">
                                    <div>₹{{ number_format((float) $invoice->total, 2) }}</div>
                                    <x-adminlte.partials.status-badge :status="$invoice->status" />
                                </div>
                            </div>
                        @empty
                            <p class="text-muted small mb-0">No invoices yet.</p>
                        @endforelse
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-muted text-uppercase small mb-2"><i class="bi bi-hdd-stack me-1"></i> Hosting account</h6>
                        @if ($order->hostingAccount)
                            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                <div>
                                    <strong>{{ $order->hostingAccount->username }}</strong>
                                    @if ($order->hostingAccount->domain)
                                        <div class="text-muted small">{{ $order->hostingAccount->domain }}</div>
                                    @endif
                                </div>
                                <x-adminlte.partials.status-badge :status="$order->hostingAccount->status" />
                            </div>
                        @else
                            <p class="text-muted small mb-0">No hosting account yet.</p>
                        @endif
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-muted text-uppercase small mb-2"><i class="bi bi-globe2 me-1"></i> Domain</h6>
                        @if ($order->domain)
                            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                <div>
                                    <strong>{{ $order->domain->name }}</strong>
                                    <div class="text-muted small">{{ ucfirst($order->domain->type) }}</div>
                                </div>
                                <x-adminlte.partials.status-badge :status="$order->domain->status" />
                            </div>
                        @else
                            <p class="text-muted small mb-0">No domain yet.</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Items --}}
            <div class="tab-pane fade {{ $activeTab === 'items' ? 'show active' : '' }}" id="items"
                 role="tabpanel" aria-labelledby="items-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Unit price</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($order->items as $item)
                                <tr>
                                    <td>
                                        {{ $item->product_name }}
                                        @if ($item->product)
                                            <div class="text-muted small">{{ $item->product->name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $item->quantity }}</td>
                                    <td class="text-end">₹{{ number_format((float) $item->unit_price, 2) }}</td>
                                    <td class="text-end fw-bold">₹{{ number_format((float) $item->total, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No items on this order.</td></tr>
                            @endforelse
                        </tbody>
                        @if ($order->items->isNotEmpty())
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-end">Total</th>
                                    <th class="text-end">₹{{ number_format((float) $order->total, 2) }}</th>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            {{-- Status History (order.* activity trail) --}}
            <div class="tab-pane fade {{ $activeTab === 'status-history' ? 'show active' : '' }}" id="status-history"
                 role="tabpanel" aria-labelledby="status-history-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($activity as $entry)
                                <tr>
                                    <td class="text-muted" style="white-space: nowrap;">{{ $entry->created_at?->format('M j, Y H:i') }}</td>
                                    <td><span class="badge bg-info">{{ $entry->action }}</span></td>
                                    <td>{{ $entry->description }}</td>
                                    <td class="text-muted">{{ $entry->user?->full_name ?? 'System' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No status history recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </x-adminlte.partials.detail-tabs>
    </x-adminlte-card>
@stop
