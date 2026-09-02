@extends('adminlte::page')
@section('title', 'Provisioning Events')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Provisioning Events</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item active">Provisioning Events</li></ol></div></div>
@stop
@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte.partials.datatable
        icon="bi bi-clock-history"
        title="All Events"
        status-field="event_status"
        :status-options="['pending' => 'Pending', 'running' => 'Running', 'completed' => 'Completed', 'failed' => 'Failed']"
        :status-value="$eventStatus"
        :columns="[
            ['label' => 'Date', 'sort' => 'created_at'],
            ['label' => 'Service', 'sort' => 'service'],
            ['label' => 'Type', 'sort' => 'event_type'],
            ['label' => 'Status', 'sort' => 'event_status'],
        ]"
        :pagination="$events"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2">
                @if(request('event_status'))<input type="hidden" name="event_status" value="{{ request('event_status') }}">@endif
                @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
                @if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
                <select name="event_type" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by event type">
                    <option value="">All Types</option>
                    @foreach (['provision','suspend','terminate','renew','migrate','update'] as $t)
                        <option value="{{ $t }}" @selected(request('event_type') === $t)>{{ ucfirst($t) }}</option>
                    @endforeach
                </select>
            </form>
        </x-slot>

        @forelse ($events as $event)
            <tr>
                <td>{{ $event->created_at?->format('Y-m-d H:i') }}</td>
                <td><a href="{{ route('admin.service-instances.show', $event->service_instance_id) }}">#{{ $event->service_instance_id }}</a></td>
                <td><span class="badge text-bg-info">{{ $event->event_type }}</span></td>
                <td><x-adminlte.partials.status-badge :status="$event->event_status" /></td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No provisioning events found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
