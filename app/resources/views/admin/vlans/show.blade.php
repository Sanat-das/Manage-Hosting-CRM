@extends('adminlte::page')

@section('title', 'VLAN — '.$vlan->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $vlan->name }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.vlans.index') }}">VLANs</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $vlan->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success')) <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert> @endif
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('admin.vlans.edit', $vlan) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>

    <x-adminlte-card icon="bi bi-info-circle" title="Details">
        <table class="table table-sm table-borderless mb-0">
            <tbody>
                <tr><th class="text-muted w-25">Name</th><td>{{ $vlan->name }}</td></tr>
                <tr><th class="text-muted">VLAN ID</th><td><span class="badge bg-info">{{ $vlan->vlan_id }}</span></td></tr>
                <tr><th class="text-muted">Datacenter</th><td>{{ $vlan->datacenter?->name ?? '—' }}</td></tr>
                <tr><th class="text-muted">Description</th><td>{{ $vlan->description ?? '—' }}</td></tr>
            </tbody>
        </table>
    </x-adminlte-card>
@stop
