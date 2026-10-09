@extends('adminlte::page')

@section('title', 'Consent Log')

@section('content_header')
    <x-ui.page-header title="Consent Log" subtitle="Marketing opt-in / opt-out history" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Consent Log','active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />
    <x-adminlte.partials.datatable
        icon="bi bi-shield-check"
        title="Consent Log"
        :search-value="$search"
        search-placeholder="Search source, contact type..."
        status-field="consent_status"
        status-placeholder="All consent states"
        :status-options="$consentStatuses"
        :status-value="$consentStatus"
        :columns="[
            ['label' => 'Date', 'sort' => 'created_at'],
            ['label' => 'Customer'],
            ['label' => 'Contact Type', 'sort' => 'contact_type'],
            ['label' => 'Consent', 'sort' => 'consent_status'],
            ['label' => 'Source', 'sort' => 'source'],
            ['label' => 'IP', 'sort' => 'ip_address'],
        ]"
        :pagination="$logs"
    >
        <x-slot name="filters">
            <div class="d-flex align-items-center gap-1">
                <label for="filter-contact-type" class="form-label small fw-medium mb-0">Contact</label>
                <select id="filter-contact-type" name="contact_type" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by contact type">
                    <option value="">All contact types</option>
                    @foreach ($contactTypes as $value => $label)
                        <option value="{{ $value }}" @selected($contactType === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </x-slot>

        @forelse ($logs as $log)
            <tr>
                <td class="text-muted small text-nowrap">{{ $log->created_at?->format('M j, H:i') }}</td>
                <td>{{ $log->customer?->full_name ?? '—' }} <span class="text-muted small">#{{ $log->customer_id }}</span></td>
                <td>{{ $log->contact_type ?? '—' }}</td>
                <td><x-adminlte.partials.status-badge :status="$log->consent_status" /></td>
                <td>{{ $log->source ?? '—' }}</td>
                <td class="text-muted small">{{ $log->ip_address ?? '—' }}</td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="6" title="No consent history." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop
