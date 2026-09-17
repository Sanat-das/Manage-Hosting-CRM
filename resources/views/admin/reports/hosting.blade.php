@extends('adminlte::page')

@section('title', 'Hosting Report')

@section('content_header')
    <x-ui.page-header title="Hosting Report" subtitle="Hosting services and utilization report" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Reports'],['label' => 'Hosting Report','active' => true]]" />
@stop

@section('content')
    <div class="row mb-4">
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-pie-chart" title="By Status">
                <table class="table table-sm mb-0">
                    @foreach ($byStatus as $s => $c)
                        <tr><td class="text-capitalize">{{ $s }}</td><td class="text-end fw-bold">{{ $c }}</td></tr>
                    @endforeach
                </table>
            </x-adminlte-card>
        </div>
    </div>

    <x-adminlte-card icon="bi bi-hdd-stack" title="All Hosting Accounts">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Customer</th><th>Product</th><th>Domain</th><th>Server</th><th>Status</th></tr>
            </thead>
            <tbody>
                @forelse ($accounts as $a)
                    <tr>
                        <td>{{ $a->customer?->full_name ?? 'â€”' }}</td>
                        <td>{{ $a->product?->name ?? 'â€”' }}</td>
                        <td>{{ $a->domain ?? 'â€”' }}</td>
                        <td class="text-muted">{{ $a->server?->name ?? 'â€”' }}</td>
                        <td>
                            <x-adminlte.partials.status-badge :status="$a->status" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No accounts.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $accounts->links() }}
    </x-adminlte-card>
@stop

