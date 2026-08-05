@extends('adminlte::page')

@section('title', $domain->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $domain->name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.domains.index') }}">Domains</a></li>
                <li class="breadcrumb-item active">{{ $domain->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <div class="d-flex justify-content-between mb-3">
        <div>
            <x-adminlte.partials.status-badge :status="$domain->status" />
            @if ($domain->isExpiringSoon())
                <span class="badge bg-warning text-dark ms-2">Expiring in {{ $domain->daysUntilExpiry() }} days</span>
            @endif
        </div>
        <div class="d-flex gap-2">
            @can('domains.manage')
                <a href="{{ route('admin.domains.edit', $domain) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
            @endcan
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card title="Domain Details">
                <table class="table table-sm table-borderless">
                    <tbody>
                        <tr><th class="w-25 text-muted">Name</th><td><strong>{{ $domain->name }}</strong></td></tr>
                        <tr><th class="text-muted">Customer</th><td>{{ $domain->customer?->full_name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Registrar</th><td>{{ $domain->registrar ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Registration date</th><td>{{ $domain->registration_date?->format('M j, Y') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Expiry date</th><td>{{ $domain->expiry_date?->format('M j, Y') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Recurring amount</th><td>{{ number_format($domain->recurring_amount ?? 0, 2) }}</td></tr>
                        <tr><th class="text-muted">Auto-renew</th><td>{{ $domain->auto_renew ? 'Yes' : 'No' }}</td></tr>
                        <tr><th class="text-muted">Nameservers</th><td><pre class="mb-0" style="white-space: pre-wrap;">{{ $domain->nameservers ?? '—' }}</pre></td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-lg-4">
            <x-adminlte-card title="Actions">
                @if ($domain->status === 'active')
                    <form method="POST" action="{{ route('admin.domains.update', $domain) }}" class="mb-2">
                        @csrf @method('PUT')
                        <input type="hidden" name="status" value="suspended">
                        <button class="btn btn-warning btn-block"><i class="bi bi-pause-circle me-1"></i> Suspend</button>
                    </form>
                @elseif ($domain->status === 'suspended')
                    <form method="POST" action="{{ route('admin.domains.update', $domain) }}" class="mb-2">
                        @csrf @method('PUT')
                        <input type="hidden" name="status" value="active">
                        <button class="btn btn-success btn-block"><i class="bi bi-play-circle me-1"></i> Unsuspend</button>
                    </form>
                @endif
                @can('domains.manage')
                    <form method="POST" action="{{ route('admin.domains.destroy', $domain) }}"
                          onsubmit="return confirm('Delete domain {{ $domain->name }}?');" class="mt-2">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger btn-block"><i class="bi bi-trash me-1"></i> Delete</button>
                    </form>
                @endcan
            </x-adminlte-card>
        </div>
    </div>
@stop
