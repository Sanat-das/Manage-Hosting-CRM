@extends('adminlte::page')

@section('title', 'My Domains')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">My Domains</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Domains</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte-card icon="bi bi-globe" title="My Domains">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Domain</th><th>Registrar</th><th>Expiry</th><th>Auto-renew</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($domains as $domain)
                    <tr>
                        <td><strong>{{ $domain->name }}</strong></td>
                        <td class="text-muted">{{ $domain->registrar ?? '—' }}</td>
                        <td class="{{ $domain->isExpiringSoon() ? 'text-warning fw-bold' : 'text-muted' }}">
                            {{ $domain->expiry_date?->format('M j, Y') ?? '—' }}
                        </td>
                        <td>{{ $domain->auto_renew ? 'Yes' : 'No' }}</td>
                        <td>
                            @php $badge = ['active'=>'success','suspended'=>'warning','expired'=>'danger','pending'=>'info'][$domain->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($domain->status) }}</span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('client.domains.show', $domain) }}" class="btn btn-sm btn-outline-primary">Details</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No domains registered.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-adminlte-card>
@stop
