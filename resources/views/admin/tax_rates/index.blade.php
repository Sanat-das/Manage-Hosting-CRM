@extends('adminlte::page')

@section('title', 'Tax Rates')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Tax Rates</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Tax Rates</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-percent"
        title="All Tax Rates"
        :search-value="$search"
        search-placeholder="Search tax rate name..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive']"
        :status-value="$status"
        :columns="[
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Rate', 'sort' => 'rate'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$rates"
    >
        <x-slot name="tools">
            <a href="{{ route('admin.tax-rates.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Tax Rate</a>
        </x-slot>

        @forelse ($rates as $rate)
            <tr>
                <td><a href="{{ route('admin.tax-rates.show', $rate) }}"><strong>{{ $rate->name ?? '—' }}</strong></a></td>
                <td>{{ $rate->rate }}%</td>
                <td><x-adminlte.partials.status-badge :status="$rate->is_active ? 'active' : 'inactive'" /></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.tax-rates.edit', $rate) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No tax rates found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
