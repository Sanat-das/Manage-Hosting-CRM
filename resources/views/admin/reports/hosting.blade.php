@extends('adminlte::page')

@section('title', 'Hosting Report')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Hosting Report</h1></div>
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
                        <td>{{ $a->customer?->full_name ?? '—' }}</td>
                        <td>{{ $a->product?->name ?? '—' }}</td>
                        <td>{{ $a->domain ?? '—' }}</td>
                        <td class="text-muted">{{ $a->server?->name ?? '—' }}</td>
                        <td>
                            @php $badge = ['active'=>'success','suspended'=>'warning','terminated'=>'danger'][$a->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($a->status) }}</span>
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
