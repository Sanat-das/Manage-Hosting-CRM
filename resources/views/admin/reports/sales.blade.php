@extends('adminlte::page')

@section('title', 'Sales Report')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Sales Report</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row mb-4">
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="'₹' . number_format($totalRevenue, 0)" text="Total Revenue" icon="bi bi-currency-rupee" theme="success" />
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
                        <td>{{ $order->customer?->full_name ?? '—' }}</td>
                        <td>
                            @php $badge = ['pending'=>'info','active'=>'success','cancelled'=>'danger','completed'=>'success'][$order->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($order->status ?? 'pending') }}</span>
                        </td>
                        <td class="text-end fw-bold">₹{{ number_format($order->total ?? 0, 2) }}</td>
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
