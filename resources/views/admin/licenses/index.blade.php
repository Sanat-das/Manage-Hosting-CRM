@extends('adminlte::page')

@section('title', 'Licenses')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Licenses</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Licenses</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte-card icon="bi bi-key" title="All Licenses">
        <div class="text-end mb-3">
            <a href="{{ route('admin.licenses.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add License</a>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Type</th><th>Key</th><th>Vendor</th><th>Seats</th><th>Expiry</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($licenses as $license)
                        <tr>
                            <td><a href="{{ route('admin.licenses.show', $license) }}"><strong>{{ $license->license_type }}</strong></a></td>
                            <td class="text-muted text-truncate" style="max-width:200px">{{ $license->license_key }}</td>
                            <td>{{ $license->vendor ?? '—' }}</td>
                            <td>{{ $license->seats_available ?? '—' }} / {{ $license->seats ?? '—' }}</td>
                            <td>{{ $license->expiry_date?->format('Y-m-d') ?? '—' }}</td>
                            <td><x-adminlte.partials.status-badge :status="$license->status" /></td>
                            <td class="text-end"><a href="{{ route('admin.licenses.edit', $license) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No licenses found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $licenses->links() }}
    </x-adminlte-card>
@stop
