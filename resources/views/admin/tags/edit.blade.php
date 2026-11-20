@extends('adminlte::page')

@section('title', 'Edit Tag — '.$tag->name)

@section('content_header')
    <x-ui.page-header title="Edit Tag: {{ $tag->name }}" subtitle="Update tag details" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Tags', 'url' => route('admin.tags.index')],
        ['label' => 'Edit', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.form-card icon="bi bi-tags" title="Edit Tag" :action="route('admin.tags.update', $tag)" submit-label="Update Tag" :cancel-url="route('admin.tags.index')">
        @method('PUT')
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" value="{{ old('name', $tag->name) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="slug" label="Slug" placeholder="auto from name" value="{{ old('slug', $tag->slug) }}" />
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="color" label="Color" placeholder="#6c757d" value="{{ old('color', $tag->color) }}" maxlength="7" pattern="#[0-9a-fA-F]{6}" required />
            </div>
        </div>
        <x-adminlte-textarea name="description" label="Description" rows="3">{{ old('description', $tag->description) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
