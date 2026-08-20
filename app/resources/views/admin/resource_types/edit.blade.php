@extends('adminlte::page')
@section('title', 'Edit Resource Type — '.$type->name)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Edit: {{ $type->name }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.resource-types.index') }}">Resource Types</a></li><li class="breadcrumb-item active">Edit</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-cpu" title="Edit Resource Type" :action="route('admin.resource-types.update', $type)" submit-label="Update" :cancel-url="route('admin.resource-types.show', $type)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" value="{{ old('name', $type->name) }}" required /></div>
            <div class="col-md-3"><x-adminlte-input name="unit" label="Unit" value="{{ old('unit', $type->unit) }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="category" label="Category" value="{{ old('category', $type->category) }}" /></div>
        </div>
        <x-adminlte-input name="description" label="Description" value="{{ old('description', $type->description) }}" />
    </x-adminlte.partials.form-card>
@stop
