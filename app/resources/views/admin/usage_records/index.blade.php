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

    <x-adminlte-card icon="bi bi-graph-up" title="All Usage Records">
        <div class="row mb-3">
            <div class="col-md-4">
                <form method="GET" action="{{ route('admin.usage-records.index') }}">
                    <x-adminlte-select name="resource_type_id" label="Filter by Resource Type">
                        <option value="">All Types</option>
                        @foreach ($resourceTypes as $type)
                            <option value="{{ $type->id }}" @selected(request('resource_type_id') == $type->id)>{{ $type->name }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
                </form>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Date</th><th>Service</th><th>Resource</th><th>Quantity</th><th>Unit Cost</th><th>Total</th></tr>
                </thead>
                <tbody>
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
                </tbody>
            </table>
        </div>
        {{ $records->links() }}
    </x-adminlte-card>
@stop
