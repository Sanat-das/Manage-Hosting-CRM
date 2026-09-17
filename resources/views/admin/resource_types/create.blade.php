@extends('adminlte::page')
@section('title', 'Add Resource Type')
@section('content_header')
    <x-ui.page-header title="Add Resource Type" subtitle="Add a new resource type" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Resource Types','url' => route('admin.resource-types.index')],['label' => 'Add Resource Type','active' => true]]" />
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-cpu" title="New Resource Type" :action="route('admin.resource-types.store')" submit-label="Save" :cancel-url="route('admin.resource-types.index')">
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" placeholder="e.g. Bandwidth" value="{{ old('name') }}" required /></div>
            <div class="col-md-3"><x-adminlte-input name="slug" label="Slug" placeholder="e.g. bandwidth" value="{{ old('slug') }}" /></div>
            <div class="col-md-3"><x-adminlte-input name="unit" label="Unit" placeholder="e.g. GB, hours" value="{{ old('unit') }}" /></div>
        </div>
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="category" label="Category" placeholder="e.g. network" value="{{ old('category') }}" /></div>
            <div class="col-md-6"><x-adminlte-input name="description" label="Description" value="{{ old('description') }}" /></div>
        </div>
    </x-adminlte.partials.form-card>
@stop

