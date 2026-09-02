@extends('adminlte::page')

@section('title', 'Usage Records')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Usage Records</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Usage Records</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-graph-up"
        title="All Usage Records"
        :search-value="$search"
        search-placeholder="Search service domain or username..."
        :columns="[
            ['label' => 'Date', 'sort' => 'recorded_at'],
            ['label' => 'Service', 'sort' => 'service'],
            ['label' => 'Resource', 'sort' => 'resource'],
            ['label' => 'Quantity'],
            ['label' => 'Unit Cost'],
            ['label' => 'Total'],
        ]"
        :pagination="$records"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2">
                <input type="hidden" name="search" value="{{ $search }}">
                @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
                @if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
                <select name="resource_type_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by resource type">
                    <option value="">All Types</option>
                    @foreach ($resourceTypes as $type)
                        <option value="{{ $type->id }}" @selected((string) request('resource_type_id') === (string) $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
            </form>
        </x-slot>

        @forelse ($records as $record)
            <tr>
                <td><a href="{{ route('admin.usage-records.show', $record) }}">{{ $record->recorded_at?->format('Y-m-d') ?? '—' }}</a></td>
                <td>{{ $record->service?->domain ?? '—' }}</td>
                <td>{{ $record->resourceType?->name ?? '—' }}</td>
                <td>{{ number_format($record->quantity, 2) }} {{ $record->resourceType?->unit ?? '' }}</td>
                <td>${{ number_format($record->unit_cost ?? 0, 4) }}</td>
                <td>${{ number_format($record->total_cost ?? 0, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No usage records found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
