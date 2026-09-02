@extends('adminlte::page')

@section('title', 'Orders')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Orders</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Orders</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    @php
        $statusLabels = [
            'pending' => 'Pending',
            'active' => 'Active',
            'suspended' => 'Suspended',
            'cancelled' => 'Cancelled',
            'terminated' => 'Terminated',
        ];
    @endphp

    <x-adminlte.partials.datatable
        icon="bi bi-cart3"
        title="All Orders"
        :search-value="$search"
        search-placeholder="Search order number, customer name, email..."
        :status-options="$statusLabels"
        :status-value="$status"
        :export-url="route('admin.orders.index')"
        export-label="Export CSV"
        :columns="[
            ['label' => 'Order', 'sort' => 'order_number'],
            ['label' => 'Customer', 'sort' => 'customer'],
            ['label' => 'Product', 'sort' => 'product'],
            ['label' => 'Total', 'sort' => 'total', 'class' => 'text-end'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Created', 'sort' => 'created_at'],
        ]"
        :pagination="$orders"
    >
        <x-slot name="tools">
            @can('orders.create')
                <a href="{{ route('admin.orders.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> New Order
                </a>
            @endcan
        </x-slot>

        @forelse ($orders as $order)
            <tr>
                <td>
                    <a href="{{ route('admin.orders.show', $order) }}"><strong>{{ $order->order_no }}</strong></a>
                </td>
                <td>
                    <a href="{{ route('admin.customers.show', $order->customer) }}">{{ $order->customer?->full_name }}</a>
                    @if ($order->customer?->user?->email)
                        <div class="text-muted small">{{ $order->customer->user->email }}</div>
                    @endif
                </td>
                <td class="text-muted">{{ $order->product?->name ?? '—' }}</td>
                <td class="text-end">₹{{ number_format((float) $order->total, 2) }}</td>
                <td>
                    <x-adminlte.partials.status-badge :status="$order->status" />
                </td>
                <td class="text-muted">{{ $order->created_at?->format('M j, Y') }}</td>
            </tr>
        @empty
            <x-ui.empty-table-row
                :col-span="6"
                icon="bi bi-cart3"
                title="No orders found"
                message="Try adjusting your search or filters."
            />
        @endforelse
    </x-adminlte.partials.datatable>
@stop
