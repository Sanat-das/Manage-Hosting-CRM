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

    <x-adminlte-card icon="bi bi-racks" title="All Racks">
        <div class="row mb-3">
            <div class="col-md-4">
                <form method="GET" action="{{ route('admin.racks.index') }}">
                    <x-adminlte-select name="datacenter_id" label="Filter by Datacenter">
                        <option value="">All Datacenters</option>
                        @foreach ($datacenters as $dc)
                            <option value="{{ $dc->id }}" @selected(request('datacenter_id') == $dc->id)>{{ $dc->name }}</option>
                        @endforeach
                    </x-adminlte-select>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
                </form>
            </div>
            <div class="col-md-8 text-end">
                <a href="{{ route('admin.racks.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1"></i> Add Rack
                </a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Datacenter</th>
                        <th>U Height</th>
                        <th>U Available</th>
                        <th>Power (W)</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
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
                                <a href="{{ route('admin.racks.edit', $rack) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">No racks found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $racks->links() }}
    </x-adminlte-card>
@stop
