@extends('adminlte::page')
@section('title', 'Provisioning Events')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Provisioning Events</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">Provisioning Events</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <x-adminlte-card icon="bi bi-clock-history" title="All Events">
        <div class="row mb-3">
            <div class="col-md-6">
                <form method="GET" action="{{ route('admin.provisioning-events.index') }}" class="d-flex gap-2">
                    <x-adminlte-select name="event_type" label="Type">
                        <option value="">All Types</option>
                        @foreach (['provision','suspend','terminate','renew','migrate','update'] as $t)
                            <option value="{{ $t }}" @selected(request('event_type') === $t)>{{ ucfirst($t) }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <x-adminlte-select name="event_status" label="Status">
                        <option value="">All</option>
                        @foreach (['pending','running','completed','failed'] as $s)
                            <option value="{{ $s }}" @selected(request('event_status') === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <button type="submit" class="btn btn-sm btn-outline-primary mt-4">Filter</button>
                </form>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Date</th><th>Service</th><th>Type</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td>{{ $event->created_at?->format('Y-m-d H:i') }}</td>
                            <td><a href="{{ route('admin.service-instances.show', $event->service_instance_id) }}">#{{ $event->service_instance_id }}</a></td>
                            <td><span class="badge bg-info">{{ $event->event_type }}</span></td>
                            <td><x-adminlte.partials.status-badge :status="$event->event_status" /></td>
                            <td class="text-end"><a href="{{ route('admin.provisioning-events.show', $event) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No provisioning events found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $events->links() }}
    </x-adminlte-card>
@stop
