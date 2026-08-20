@extends('adminlte::page')

@section('title', 'VLANs')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">VLANs</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">VLANs</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif

    <x-adminlte-card icon="bi bi-diagram-3" title="All VLANs">
        <div class="text-end mb-3">
            <a href="{{ route('admin.vlans.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i> Add VLAN</a>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Name</th><th>VLAN ID</th><th>Datacenter</th><th>Description</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($vlans as $vlan)
                        <tr>
                            <td><a href="{{ route('admin.vlans.show', $vlan) }}"><strong>{{ $vlan->name }}</strong></a></td>
                            <td><span class="badge bg-info">{{ $vlan->vlan_id }}</span></td>
                            <td>{{ $vlan->datacenter?->name ?? '—' }}</td>
                            <td class="text-muted">{{ $vlan->description ?? '—' }}</td>
                            <td class="text-end"><a href="{{ route('admin.vlans.edit', $vlan) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No VLANs found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $vlans->links() }}
    </x-adminlte-card>
@stop
