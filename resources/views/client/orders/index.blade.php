@extends('adminlte::page')

@section('title', 'My Orders')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">My Orders</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Orders</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @php
        // Status vocabulary shown to the customer. Every value is one of
        // Order::STATUSES — the controller filters against that list, so a
        // hand-typed ?status= cannot reach the query.
        $statusLabels = [
            'pending' => 'Pending',
            'paid' => 'Paid',
            'provisioning' => 'Provisioning',
            'active' => 'Active',
            'failed' => 'Failed',
            'suspended' => 'Suspended',
            'cancelled' => 'Cancelled',
            'terminated' => 'Terminated',
        ];
        $cycleLabels = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-Annual',
            'annual' => 'Annual', 'biennial' => 'Biennial', 'one_time' => 'One-Time'];
    @endphp

    <x-adminlte.partials.datatable
        icon="bi bi-bag-check"
        title="Orders"
        :search-value="$search"
        search-placeholder="Search order number, product or domain..."
        :status-options="$statusLabels"
        :status-value="$status ?? ''"
        :columns="[
            ['label' => 'Order #', 'sort' => 'order_number'],
            ['label' => 'Placed', 'sort' => 'created_at'],
            ['label' => 'Items'],
            ['label' => 'Billing'],
            ['label' => 'Total', 'sort' => 'total'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$orders"
    >
        @forelse ($orders as $order)
            <tr data-row-link="{{ route('client.orders.show', $order->id) }}">
                <td>
                    <a href="{{ route('client.orders.show', $order->id) }}" class="text-decoration-none">
                        <strong>{{ $order->order_no }}</strong>
                    </a>
                </td>
                <td class="text-muted">{{ $order->created_at?->format('M j, Y') ?? '—' }}</td>
                <td>
                    {{-- The item lines are what was actually bought; the order's
                         own product is only a fallback for rows predating them. --}}
                    @if ($order->items->isNotEmpty())
                        {{ $order->items->first()->product_name ?? '—' }}
                        @if ($order->items->count() > 1)
                            <span class="text-muted small">+{{ $order->items->count() - 1 }} more</span>
                        @endif
                    @else
                        {{ $order->product?->name ?? '—' }}
                    @endif
                    @if ($order->domain_name)
                        <div class="text-muted small">{{ $order->domain_name }}</div>
                    @endif
                </td>
                <td class="text-muted">{{ $cycleLabels[$order->billing_cycle] ?? ($order->billing_cycle ?? '—') }}</td>
                <td>₹{{ number_format((float) $order->total, 2) }}</td>
                <td>
                    <x-adminlte.partials.status-badge :status="$order->status" />
                </td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('client.orders.show', $order->id) }}" class="btn btn-sm btn-outline-primary btn-icon" title="View" aria-label="View"><i class="bi bi-eye"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            @if ($search !== '' || $status !== null)
                <tr><td colspan="7" class="text-center text-muted py-4">No orders match your filters.</td></tr>
            @else
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <i class="bi bi-bag fs-1 text-muted d-block mb-2"></i>
                        <p class="text-muted mb-3">You haven't placed any orders yet.</p>
                        <a href="{{ route('client.store.index') }}" class="btn btn-primary">
                            <i class="bi bi-shop me-1"></i>Browse the Store
                        </a>
                    </td>
                </tr>
            @endif
        @endforelse
    </x-adminlte.partials.datatable>

    @push('js')
        <script>
            document.querySelectorAll('tr[data-row-link]').forEach(function (row) {
                row.style.cursor = 'pointer';
                row.addEventListener('click', function (e) {
                    if (e.target.closest('a, button, input, select')) return;
                    window.location = row.dataset.rowLink;
                });
            });
        </script>
    @endpush
@stop
