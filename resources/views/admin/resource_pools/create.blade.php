@extends('adminlte::page')
@section('title', 'Add Resource Pool')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Add Resource Pool</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.resource-pools.index') }}">Resource Pools</a></li><li class="breadcrumb-item active">Add</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-collection" title="New Resource Pool" :action="route('admin.resource-pools.store')" submit-label="Save Pool" :cancel-url="route('admin.resource-pools.index')">
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" placeholder="e.g. CPU Pool A" value="{{ old('name') }}" required /></div>
            <div class="col-md-6"><x-adminlte-input name="pool_type" label="Pool Type" placeholder="e.g. cpu, memory, storage" value="{{ old('pool_type') }}" required /></div>
        </div>
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="total_capacity" label="Total Capacity" type="number" step="0.01" min="0" value="{{ old('total_capacity') }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="unit" label="Unit" placeholder="e.g. GHz, GB" value="{{ old('unit') }}" /></div>
            <div class="col-md-4">
                <x-adminlte-select name="server_id" label="Server">
                    <option value="">— None —</option>
                    @foreach ($servers as $srv)
                        <option value="{{ $srv->id }}" @selected(old('server_id') == $srv->id)>{{ $srv->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <x-adminlte-select name="status" label="Status">
            <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
            <option value="disabled" @selected(old('status') === 'disabled')>Disabled</option>
        </x-adminlte-select>
    </x-adminlte.partials.form-card>
@stop
