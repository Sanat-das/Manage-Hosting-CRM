@extends('adminlte::page')

@section('title', 'Racks')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Racks</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Racks</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable
        icon="bi bi-hdd-stack"
        title="All Racks"
        :search-value="$search"
        search-placeholder="Search rack name..."
        :columns="[
            ['label' => 'ID', 'sort' => 'id'],
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Datacenter', 'sort' => 'datacenter'],
            ['label' => 'U Height', 'sort' => 'u_height'],
            ['label' => 'U Available', 'sort' => 'u_available'],
            ['label' => 'Power (W)', 'sort' => 'power'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$racks"
    >
        <x-slot name="tools">
            <form method="GET" action="{{ url()->current() }}" class="d-inline-flex align-items-center gap-2 me-2">
                <input type="hidden" name="search" value="{{ $search }}">
                @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
                @if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
                <select name="datacenter_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by datacenter">
                    <option value="">All Datacenters</option>
                    @foreach ($datacenters as $dc)
                        <option value="{{ $dc->id }}" @selected((string) request('datacenter_id') === (string) $dc->id)>{{ $dc->name }}</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('admin.racks.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Add Rack
            </a>
        </x-slot>

        @forelse ($racks as $rack)
            <tr>
                <td>{{ $rack->id }}</td>
                <td><a href="{{ route('admin.racks.show', $rack) }}"><strong>{{ $rack->name }}</strong></a></td>
                <td>{{ $rack->datacenter?->name ?? '—' }}</td>
                <td>{{ $rack->u_height ?? '—' }}</td>
                <td>{{ $rack->u_available ?? '—' }}</td>
                <td>{{ $rack->power_capacity_watts ?? '—' }}</td>
                <td><x-adminlte.partials.status-badge :status="$rack->status" /></td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.racks.edit', $rack) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No racks found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
