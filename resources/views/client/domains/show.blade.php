@extends('adminlte::page')

@section('title', $domain->name)

@section('content_header')
    <x-ui.page-header :title="$domain->name" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Domains', 'url' => route('client.domains.index')],
        ['label' => $domain->name, 'active' => true],
    ]" />
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
                                <span class="badge text-bg-warning ms-1">Expiring soon</span>
                            @endif
                        </td>
                    </tr>
                    <tr><th class="text-muted">Auto-renew</th><td>{{ $domain->auto_renew ? 'Yes' : 'No' }}</td></tr>
                    <tr>
                        <th class="text-muted">Status</th>
                        <td>
                            <x-adminlte.partials.status-badge :status="$domain->status" />
                        </td>
                    </tr>
                    <tr><th class="text-muted">Nameservers</th><td><pre class="mb-0 small" style="white-space:pre-wrap">{{ $domain->nameservers ?? '—' }}</pre></td></tr>
                </table>
            </x-adminlte-card>
        </div>

        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-shield-lock" title="Security & Locks">
                <ul class="list-unstyled mb-0">
                    {{-- Directives stay on their own lines: Blade's directive
                         regexes are anchored on a non-word boundary, so a
                         directive glued to a preceding word character is left
                         as literal text instead of being compiled. --}}
                    <li class="mb-2">Domain lock: <strong>
                        @if ($domain->lock_status)
                            <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>{{ __('Locked') }}
                        @else
                            <i class="bi bi-unlock me-1" aria-hidden="true"></i>{{ __('Unlocked') }}
                        @endif
                    </strong></li>
                    <li class="mb-2">Privacy protection: <strong>{{ $domain->privacy_enabled ? 'On' : 'Off' }}</strong></li>
                    <li>DNS management: <strong>{{ $domain->dns_management ? 'Included' : '—' }}</strong></li>
                </ul>
            </x-adminlte-card>
        </div>
    </div>
@stop
