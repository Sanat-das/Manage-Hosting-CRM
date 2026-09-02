@extends('adminlte::page')

@section('title', 'Edit: ' . $group->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Edit Customer Group</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.customer-groups.index') }}">Customer Groups</a></li>
                <li class="breadcrumb-item active">{{ $group->name }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-folder" title="Edit: {{ $group->name }}"
        :action="route('admin.customer-groups.update', $group)" submit-label="Save Changes"
        :cancel-url="route('admin.customer-groups.show', $group)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Group Name" value="{{ old('name', $group->name) }}" required />
            </div>
            <div class="col-md-3">
                <x-adminlte-select name="parent_id" label="Parent Group">
                    <option value="">None</option>
                    @foreach ($parentGroups as $pg)
                        <option value="{{ $pg->id }}" @selected(old('parent_id', $group->parent_id) == $pg->id)>{{ $pg->name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-3">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', $group->status) === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $group->status) === 'inactive')>Inactive</option>
                </x-adminlte-select>
            </div>
        </div>
        <x-adminlte-textarea name="description" label="Description" rows="2">{{ old('description', $group->description) }}</x-adminlte-textarea>
        <x-adminlte-input name="sort_order" type="number" min="0" label="Sort Order" value="{{ old('sort_order', $group->sort_order) }}" />
    </x-adminlte.partials.form-card>
@stop
