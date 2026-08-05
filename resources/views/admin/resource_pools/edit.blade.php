@extends('adminlte::page')
@section('title', 'Edit Resource Pool — '.$resourcePool->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Edit: {{ $resourcePool->name }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.resource-pools.index') }}">Resource Pools</a></li><li class="breadcrumb-item active">Edit</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-collection" title="Edit Resource Pool" :action="route('admin.resource-pools.update', $resourcePool)" submit-label="Update" :cancel-url="route('admin.resource-pools.show', $resourcePool)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" value="{{ old('name', $resourcePool->name) }}" required /></div>
            <div class="col-md-6"><x-adminlte-input name="total_capacity" label="Total Capacity" type="number" step="0.01" min="0" value="{{ old('total_capacity', $resourcePool->total_capacity) }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="unit" label="Unit" value="{{ old('unit', $resourcePool->unit) }}" /></div>
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', $resourcePool->status) === 'active')>Active</option>
                    <option value="disabled" @selected(old('status') === 'disabled')>Disabled</option>
                </x-adminlte-select>
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
