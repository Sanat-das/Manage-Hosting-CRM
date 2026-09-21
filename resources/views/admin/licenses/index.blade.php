@extends('adminlte::page')

@section('title', 'Licenses')

@section('content_header')
    <x-ui.page-header title="Licenses" subtitle="Overview and management of licenses" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Licenses','active' => true]]" />
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-key"
        title="All Licenses"
        :search-value="$search"
        search-placeholder="Search type, key, vendor..."
        :status-options="['active' => 'Active', 'expired' => 'Expired', 'revoked' => 'Revoked']"
        :status-value="$status"
        :columns="[
            ['label' => 'Type', 'sort' => 'license_type'],
            ['label' => 'Key', 'sort' => 'license_key'],
            ['label' => 'Vendor', 'sort' => 'vendor'],
            ['label' => 'Seats', 'sort' => 'seats'],
            ['label' => 'Expiry', 'sort' => 'expiry_date'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$licenses"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.licenses.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add License</a>
        </x-slot>

        @forelse ($licenses as $license)
            <tr>
                <td><a href="{{ route('admin.licenses.show', $license) }}"><strong>{{ $license->license_type }}</strong></a></td>
                <td class="text-muted" title="{{ $license->license_key }}">{{ $license->license_key }}</td>
                <td>{{ $license->vendor ?? '—' }}</td>
                <td>{{ $license->seats_available ?? '—' }} / {{ $license->seats ?? '—' }}</td>
                <td>{{ $license->expiry_date?->format('Y-m-d') ?? '—' }}</td>
                <td><x-adminlte.partials.status-badge :status="$license->status" /></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.licenses.edit', $license) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No licenses found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop

