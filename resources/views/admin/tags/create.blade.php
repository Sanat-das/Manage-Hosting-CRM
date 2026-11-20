@extends('adminlte::page')

@section('title', 'Add Tag')

@section('content_header')
    <x-ui.page-header title="Add Tag" subtitle="Create an inventory tag" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Tags', 'url' => route('admin.tags.index')],
        ['label' => 'Add', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.form-card icon="bi bi-tags" title="New Tag" :action="route('admin.tags.store')" submit-label="Save Tag" :cancel-url="route('admin.tags.index')">
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Production" value="{{ old('name') }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="slug" label="Slug" placeholder="auto from name" value="{{ old('slug') }}" />
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="color" label="Color" placeholder="#6c757d" value="{{ old('color', '#6c757d') }}" maxlength="7" pattern="#[0-9a-fA-F]{6}" required />
            </div>
        </div>
        <x-adminlte-textarea name="description" label="Description" rows="3">{{ old('description') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
