@extends('adminlte::page')
@section('title', 'Edit Resource Type — '.$type->name)
@section('content_header')
    <x-ui.page-header title="Edit: {{ $type->name }}" subtitle="Update resource type details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Resource Types','url' => route('admin.resource-types.index')],['label' => 'Edit','active' => true]]" />
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

