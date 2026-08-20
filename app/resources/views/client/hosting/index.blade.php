@extends('adminlte::page')

@section('title', 'Products/Services')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Products/Services</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Products/Services</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte-card icon="bi bi-hdd-stack" title="Products/Services">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Product</th><th>Domain</th><th>Server</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($accounts as $account)
                    <tr>
                        <td><strong>{{ $account->product?->name ?? '—' }}</strong></td>
                        <td>{{ $account->domain ?? '—' }}</td>
                        <td class="text-muted">{{ $account->server?->name ?? '—' }}</td>
                        <td>
                            @php $badge = ['active'=>'success','suspended'=>'warning','terminated'=>'danger','pending'=>'info'][$account->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($account->status) }}</span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('client.hosting.show', $account) }}" class="btn btn-sm btn-outline-primary">Details</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No products/services found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-adminlte-card>
@stop
