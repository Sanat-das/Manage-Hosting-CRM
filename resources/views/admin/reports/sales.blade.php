@extends('adminlte::page')

@section('title', 'Sales Report')

@section('content_header')
    <x-ui.page-header title="Sales Report" subtitle="Sales performance and order trends" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Reports'],['label' => 'Sales Report','active' => true]]" />
@stop

@section('content')
    <div class="row mb-4">
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="'â‚¹' . number_format($totalRevenue, 0)" text="Total Revenue" icon="bi bi-currency-rupee" theme="success" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$orderCount" text="Total Orders" icon="bi bi-cart" theme="primary" />
        </div>
    </div>

    <x-adminlte-card icon="bi bi-funnel" title="Filter">
        <form method="GET" class="d-flex gap-2 align-items-end">
            <x-adminlte-input name="from" type="date" label="From" value="{{ $from }}" />
            <x-adminlte-input name="to" type="date" label="To" value="{{ $to }}" />
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i> Filter</button>
            <a href="{{ route('admin.reports.export', ['type' => 'orders', 'from' => $from, 'to' => $to]) }}" class="btn btn-outline-success"><i class="bi bi-download me-1"></i> Export CSV</a>
        </form>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-cart" title="Orders">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Order #</th><th>Customer</th><th>Status</th><th class="text-end">Total</th><th>Date</th></tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td><strong>#{{ $order->id }}</strong></td>
                        <td>{{ $order->customer?->full_name ?? 'â€”' }}</td>
                        <td>
                            <x-adminlte.partials.status-badge :status="$order->status ?? 'pending'" />
                        </td>
                        <td class="text-end fw-bold">â‚¹{{ number_format($order->total ?? 0, 2) }}</td>
                        <td class="text-muted">{{ $order->created_at?->format('M j, Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No orders in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $orders->links() }}
    </x-adminlte-card>
@stop

