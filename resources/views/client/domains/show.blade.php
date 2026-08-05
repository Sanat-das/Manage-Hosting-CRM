@extends('adminlte::page')

@section('title', $domain->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $domain->name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.domains.index') }}">Domains</a></li>
                <li class="breadcrumb-item active">{{ $domain->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-globe" title="Domain Details">
                <table class="table table-sm table-borderless">
                    <tr><th class="w-25 text-muted">Domain</th><td><strong>{{ $domain->name }}</strong></td></tr>
                    <tr><th class="text-muted">Registrar</th><td>{{ $domain->registrar ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Registration date</th><td>{{ $domain->registration_date?->format('M j, Y') ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Expiry date</th>
                        <td class="{{ $domain->isExpiringSoon() ? 'text-warning fw-bold' : '' }}">
                            {{ $domain->expiry_date?->format('M j, Y') ?? '—' }}
                            @if ($domain->isExpiringSoon())
                                <span class="badge bg-warning text-dark ms-1">Expiring soon</span>
                            @endif
                        </td>
                    </tr>
                    <tr><th class="text-muted">Auto-renew</th><td>{{ $domain->auto_renew ? 'Yes' : 'No' }}</td></tr>
                    <tr>
                        <th class="text-muted">Status</th>
                        <td>
                            @php $badge = ['active'=>'success','suspended'=>'warning','expired'=>'danger','pending'=>'info'][$domain->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($domain->status) }}</span>
                        </td>
                    </tr>
                    <tr><th class="text-muted">Nameservers</th><td><pre class="mb-0 small" style="white-space:pre-wrap">{{ $domain->nameservers ?? '—' }}</pre></td></tr>
                </table>
            </x-adminlte-card>
        </div>

        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-shield-lock" title="Security & Locks">
                <ul class="list-unstyled mb-0">
                    <li class="mb-2">Domain lock: <strong>{{ $domain->lock_status ? '🔒 Locked' : '🔓 Unlocked' }}</strong></li>
                    <li class="mb-2">Privacy protection: <strong>{{ $domain->privacy_enabled ? 'On' : 'Off' }}</strong></li>
                    <li>DNS management: <strong>{{ $domain->dns_management ? 'Included' : '—' }}</strong></li>
                </ul>
            </x-adminlte-card>
        </div>
    </div>
@stop
